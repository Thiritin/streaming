<?php

namespace App\Models;


use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Facades\Http;

class ServerUser extends Pivot
{
    protected $table = 'server_user';

    public $timestamps = [];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function server()
    {
        return $this->belongsTo(Server::class);
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
