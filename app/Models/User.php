<?php

namespace App\Models;

use App\Enum\ServerStatusEnum;
use App\Enum\ServerTypeEnum;
use App\Enum\UserLevelEnum;
use App\Events\UserWaitingForProvisioningEvent;
use Filament\Models\Contracts\FilamentUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles;

    public mixed $provisioning;

    protected $guarded = [];
    protected $appends = ["role"];

    protected $hidden = [
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'is_provisioning' => 'boolean',
        'timeout_expires_at' => 'datetime',
    ];

    public function server()
    {
        return $this->belongsTo(Server::class);
    }

    public function clients(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Client::class);
    }

    public function getOrAssignServer()
    {
        $server = $this->server;

        if (is_null($server) || is_null($this->streamkey)) {
            if ($this->assignServerToUser()) {
                return $this->fresh()->server;
            }
            return null;
        }

        return $server;
    }

    public function getUserStreamUrls(): array
    {
        $server = $this->getOrAssignServer();
        if (is_null($server)) {
            return [
                'urls' => null,
                'client_id' => null,
            ];
        }

        $viewerIp = request()->ip();
        $hostname = $server->hostname;

        if ($this->isEfWifiIp($viewerIp)) {
            $localDomain = env('LOCAL_STREAMING_DOMAIN', 'local.stream.eurofurence.org');
            $server = Server::where('hostname', $localDomain)->first();
            $client = $this->clients()->create([
                'server_id' => $server->id,
            ]);
        }

        $client = $this->clients()
            ->whereNull('start')
            ->whereNull('stop')
            ->firstOrCreate([
                'server_id' => $server->id,
            ]);

        $data['urls'] = [];
        $data['client_id'] = $client->id;
        $protocol = app()->isLocal() ? 'http' : 'https';

        foreach (['original', 'fhd', 'hd', 'sd', 'ld', 'audio_hd', 'audio_sd'] as $quality) {
            $qualityUrl = ($quality !== 'original') ? "_".$quality : "";
            $data['urls'][$quality] = $protocol."://$hostname/live/livestream$qualityUrl.flv?streamkey=".$this->streamkey."&client_id=".$client->id;
        }

        return $data;
    }

    /**
     * Check if the IP belongs to EF WiFi using CIDR ranges (IPv4 + IPv6 supported)
     */
    protected function isEfWifiIp(string $ip): bool
    {
        $cidrRanges = [
            env('LOCAL_STREAMING_IPV6'),
            env('LOCAL_STREAMING_IPV4'),
        ];

        foreach ($cidrRanges as $cidr) {
            if (!empty($cidr) && $this->ipInCidr($ip, $cidr)) {
                return true;
            }
        }

        return false;
    }

    protected function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $mask] = explode('/', $cidr);
        $ipBin     = inet_pton($ip);
        $subnetBin = inet_pton($subnet);

        if ($ipBin === false || $subnetBin === false) {
            return false; // invalid IP or subnet
        }

        $mask = (int) $mask;
        $bytes = (int) floor($mask / 8);
        $bits = $mask % 8;

        // Compare whole bytes
        if ($bytes && substr($ipBin, 0, $bytes) !== substr($subnetBin, 0, $bytes)) {
            return false;
        }

        // Compare remaining bits if needed
        if ($bits) {
            $maskBits = ~((1 << (8 - $bits)) - 1) & 0xFF;
            if ((ord($ipBin[$bytes]) & $maskBits) !== (ord($subnetBin[$bytes]) & $maskBits)) {
                return false;
            }
        }

        return true;
    }

    public function assignServerToUser(): bool
    {
        $server = Server::where('status', ServerStatusEnum::ACTIVE)
            ->where('type', ServerTypeEnum::EDGE)
            ->addSelect([
                'client_count' => Client::selectRaw('count(*)')
                    ->whereColumn('clients.server_id', 'servers.id')
                    ->connected()
            ])
            ->groupBy('servers.id')
            ->orderBy('client_count', 'desc')
            ->selectRaw("servers.id, max_clients as max_clients")
            ->havingRaw('client_count < max_clients')
            ->first();

        if (is_null($server) || is_null($server->id)) {
            if ($this->is_provisioning === false) {
                UserWaitingForProvisioningEvent::dispatch($this);
            }
            $this->update(['server_id' => null, 'streamkey' => null]);
            return false;
        }

        $this->update([
            'server_id' => $server->id,
            'streamkey' => Str::random(32),
        ]);

        if ($this->is_provisioning) {
            $this->update(['is_provisioning' => false]);
        }

        return true;
    }

    public function messages(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function canAccessPanel(\Filament\Panel $panel): bool
    {
        return $this->can('filament.access');
    }

    public function isStaff(): bool
    {
        return $this->hasAnyRole(['Admin', 'Moderator']);
    }

    public function getRoleAttribute(): Role|null
    {
        return $this->roles()->orderBy('roles.priority', 'desc')->first(['name', 'color']);
    }
}
