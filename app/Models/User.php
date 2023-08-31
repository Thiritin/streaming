<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enum\ServerStatusEnum;
use App\Enum\ServerTypeEnum;
use App\Enum\UserLevelEnum;
use App\Events\UserWaitingForProvisioningEvent;
use Filament\Models\Contracts\FilamentUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles;

    public mixed $provisioning;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $guarded = [];

    protected $appends = ["role"];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
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

        $hostname = $server->hostname;

        $client = $this->clients()
            ->whereNull('start')
            ->whereNull('stop')
            ->firstOrCreate([
                'server_id' => $server->id,
            ]);

        $data['urls'] = [];
        $data['client_id'] = $client->id;
        $protocol = app()->isLocal() ? 'http' : 'https';
        // $protocol = "https";
        foreach (['original', 'fhd', 'hd', 'sd', 'ld', 'audio_hd', 'audio_sd'] as $quality) {
            $qualityUrl = ($quality !== 'original') ? "_".$quality : "";
            $data['urls'][$quality] = $protocol."://$hostname/live/livestream$qualityUrl.flv?streamkey=".$this->streamkey."&client_id=".$client->id;
        }
        return $data;
    }

    public function assignServerToUser(): bool
    {
        $server = Server::where('status', ServerStatusEnum::ACTIVE)
            ->where('type', ServerTypeEnum::EDGE)
            ->leftJoin('clients', function (JoinClause $join) {
                $join->on('clients.server_id', '=', 'servers.id');
                $join->on('clients.stop', \DB::raw('NULL'));
                $join->on('clients.start', 'IS NOT', \DB::raw('NULL'));
            })
            ->groupBy('servers.id')
            ->orderBy('client_count',
                'desc') // Desc fill servers with most clients first, Asc fill servers with least clients first
            ->selectRaw("servers.id, count(clients.id) as client_count,max_clients as max_clients")
            ->havingRaw('client_count < max_clients')
            ->first();

        if (is_null($server) || is_null($server->id)) {
            if ($this->is_provisioning === false) {
                UserWaitingForProvisioningEvent::dispatch($this);
            }
            $this->update(['server_id' => null,'streamkey' => null]);
            return false;
        }

        // Assign Server to User
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

    public function canAccessFilament(): bool
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
