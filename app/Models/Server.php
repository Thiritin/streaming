<?php

namespace App\Models;

use App\Enum\ServerStatusEnum;
use Illuminate\Database\Eloquent\Model;

class Server extends Model
{
    protected $guarded = [];

    protected $casts = [
        'status' => ServerStatusEnum::class,
    ];

    public function user()
    {
        return $this->belongsToMany(User::class)->using(ServerUser::class);
    }
}
