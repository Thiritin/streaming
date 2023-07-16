<?php

namespace App\Models;


use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Facades\Http;

class ServerUser extends Pivot
{
    protected $table = 'server_user';

    public $timestamps = [];

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

    public function clients()
    {
        return $this->hasMany(Client::class,'server_user_id','id');
    }
}
