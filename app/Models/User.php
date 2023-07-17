<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enum\ServerStatusEnum;
use App\Enum\ServerTypeEnum;
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

class User extends Authenticatable implements FilamentUser
{
    use HasApiTokens, HasFactory, Notifiable;

    public mixed $provisioning;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $guarded = [];

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
    ];

    public function server()
    {
        return $this->belongsToMany(Server::class)->using(ServerUser::class);
    }

    public function getOrAssignServer()
    {
        $serverUser = ServerUser::where('user_id', $this->id)->whereNull('stop')->first();

        if (is_null($serverUser) || is_null($serverUser->streamkey)) {

            if ($this->assignServerToUser()) {
                return ServerUser::where('user_id', $this->id)->whereNull('stop')->first();
            }

            return null;
        }

        return $serverUser;
    }

    public function getUserStreamUrls(): array
    {
        $serverUser = $this->getOrAssignServer();
        if (is_null($serverUser)) {
            return [
                'urls' => null,
                'client_id' => null,
            ];
        }

        $hostname = $serverUser->server->hostname;

        $client = $serverUser->clients()->create();

        $data['urls'] = [];
        $data['client_id'] = $client->id;
        // $protocol = app()->isLocal() ? 'http' : 'https';
        $protocol = "https";
        foreach (['original', 'fhd', 'hd', 'sd', 'ld', 'audio_hd', 'audio_sd'] as $quality) {
            $qualityUrl = ($quality !== 'original') ? "_" . $quality : "";
            $data['urls'][$quality] = $protocol . "://$hostname/live/livestream$qualityUrl.flv?streamkey=" . $serverUser->streamkey . "&client_id=" . $client->id;
        }
        return $data;
    }

    public function assignServerToUser(): bool
    {
        $server = Server::where('status', ServerStatusEnum::ACTIVE)
            ->where('type', ServerTypeEnum::EDGE)
            ->leftJoin('server_user', function (JoinClause $join) {
                $join->on('server_user.server_id', '=', 'servers.id');
                $join->on('server_user.stop', \DB::raw('NULL'));
            })
            ->groupBy('servers.id')
            ->orderBy('client_count', 'desc') // Desc fill servers with most clients first, Asc fill servers with least clients first
            ->selectRaw("servers.id, count(server_user.id) as client_count,max_clients as max_clients")
            ->havingRaw('client_count < max_clients')
            ->first();

        if (is_null($server) || is_null($server->id)) {
            if ($this->is_provisioning === false) {
                UserWaitingForProvisioningEvent::dispatch($this);
            }
            return false;
        }

        $this->server()->attach($server->id, [
            'streamkey' => Str::random(32),
        ]);

        if ($this->is_provisioning) {
            $this->update(['is_provisioning' => false]);
        }

        return true;
    }

    public function canAccessFilament(): bool
    {
        return $this->is_admin;
    }
}
