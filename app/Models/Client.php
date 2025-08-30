<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;

class Client extends Model
{
    protected $guarded = [];

    protected $casts = [
        'start' => 'datetime',
        'stop' => 'datetime',
    ];

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
        if ($this->stop !== null) {
            return false;
        }

        $this->loadMissing('server');

        $proto = app()->isLocal() ? 'http' : 'https';
        $hostname = app()->isLocal() ? 'stream:1985' : $this->server->hostname;
        Http::withBasicAuth(config('services.srs.username'), config('services.srs.password'))->delete($proto.'://'.$hostname.'/api/v1/clients/'.$this->client_id);

        return true;
    }

    public function scopeConnected(Builder $query): void
    {
        $query->whereNull('stop')->whereNotNull('start');
    }
}
