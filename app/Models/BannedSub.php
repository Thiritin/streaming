<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BannedSub extends Model
{
    public const KIND_BAN = 'ban';

    public const KIND_TIMEOUT = 'timeout';

    protected $fillable = [
        'sub',
        'kind',
        'reason',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
