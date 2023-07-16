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
        if(is_null($this->server)) {
            $this->load('server');
        }
        Http::withBasicAuth(config('services.srs.username'),config('services.srs.password'))->delete("https://".$this->server->hostname.'/api/v1/clients/'.$this->client_id);
        return true;
    }
}
