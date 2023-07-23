<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;

class Client extends Model
{
    protected $guarded = [];
    protected $casts = [
        'start' => 'datetime',
        'stop' => 'datetime',
    ];

    public function serverUser()
    {
        return $this->belongsTo(ServerUser::class,'server_user_id','id','server_user_id');
    }

    public function disconnect()
    {
        $this->load('server');
        if(is_null($this->server)) {
            $this->load('serverUser.server');
        }
        $proto = app()->isLocal() ? "http" : "https";
        $hostname = app()->isLocal() ? "stream:1985" : $this->server->hostname;
        Http::withBasicAuth(config('services.srs.username'),config('services.srs.password'))->delete($proto."://".$hostname.'/api/v1/clients/'.$this->client_id);
        return true;
    }
}
