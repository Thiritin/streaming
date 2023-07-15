<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enum\ServerStatusEnum;
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
    ];

    public function server()
    {
        return $this->belongsToMany(Server::class)->using(ServerUser::class);
    }


    public function isStreamRunning():bool
    {
        return ServerUser::where('user_id', $this->id)->whereNull('stop')->whereNotNull('start')->exists();
    }

    public function getUserStreamUrls(): ?array
    {
        $server = ServerUser::where('user_id', $this->id)->whereNull('stop')->first();

        if (is_null($server) || is_null($server->streamkey)) {
            if($this->assignServerToUser()) {
                $server = ServerUser::where('user_id', $this->id)->whereNull('stop')->first();
            } else {
                return null;
            }
        }

        $hostname = $server->server->hostname;

        return [
            "original" => "https://$hostname/live/livestream.flv?streamkey=" . $server->streamkey,
            "fhd" => "https://$hostname/live/livestream_fhd.flv?streamkey=" . $server->streamkey,
            "hd" => "https://$hostname/live/livestream_hd.flv?streamkey=" . $server->streamkey,
            "sd" => "https://$hostname/live/livestream_sd.flv?streamkey=" . $server->streamkey,
            "ld" => "https://$hostname/live/livestream_ld.flv?streamkey=" . $server->streamkey,
            "audio_hd" => "https://$hostname/live/livestream_audio_hd.flv?streamkey=" . $server->streamkey,
            "audio_sd" => "https://$hostname/live/livestream_audio_sd.flv?streamkey=" . $server->streamkey,
        ];
    }

    public function assignServerToUser(): bool
    {
        $server = Server::where('status', ServerStatusEnum::ACTIVE->value)
            ->leftJoin('server_user', function (JoinClause $join) {
                $join->on('server_user.server_id', '=', 'servers.id');
                $join->on('server_user.stop', \DB::raw('NULL'));
            })
            ->groupBy('servers.id')
            ->orderBy('client_count', 'asc')
            ->selectRaw("servers.id, count(server_user.id) as client_count,max_clients as max_clients")
            ->havingRaw('client_count < max_clients')
            ->first();

        if (is_null($server) || is_null($server->id)) {
            return false;
        }

        $this->server()->attach($server->id, [
            'streamkey' => Str::random(32),
        ]);

        if ($this->is_provisioning) {
            $this->update(['is_provisioning' => false]);
            return false;
        }

        return true;
    }

    public function canAccessFilament(): bool
    {
        return $this->is_admin;
    }
}
