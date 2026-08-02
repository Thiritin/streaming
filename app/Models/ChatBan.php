<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatBan extends Model
{
    protected $fillable = [
        'user_id',
        'banned_by_user_id',
        'reason',
        'expires_at',
        'lifted_at',
        'lifted_by_user_id',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'lifted_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function bannedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'banned_by_user_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('lifted_at')
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function isPermanent(): bool
    {
        return $this->expires_at === null;
    }

    public function lift(?User $moderator = null): void
    {
        $this->update([
            'lifted_at' => now(),
            'lifted_by_user_id' => $moderator?->id,
        ]);
    }
}
