<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A one-shot code for `/link <code>`. See the create migration.
 */
class TelegramLinkCode extends Model
{
    protected $fillable = [
        'code',
        'created_by',
        'telegram_chat_id',
        'expires_at',
        'used_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function chat(): BelongsTo
    {
        return $this->belongsTo(TelegramChat::class, 'telegram_chat_id');
    }

    /**
     * Crockford-ish base32, no vowels: it is read off a screen and typed into a phone,
     * usually by somebody standing in a hall.
     */
    public static function mint(?User $user = null): self
    {
        do {
            $code = Str::upper(Str::random(3).'-'.Str::random(3));
            $code = preg_replace('/[^A-Z0-9-]/', (string) random_int(2, 9), $code) ?? $code;
        } while (self::where('code', $code)->exists());

        return self::create([
            'code' => $code,
            'created_by' => $user?->id,
            'expires_at' => now()->addMinutes((int) config('telegram.link_code_ttl_minutes', 30)),
        ]);
    }

    public function usable(): bool
    {
        return $this->used_at === null && $this->expires_at->isFuture();
    }

    public function status(): string
    {
        if ($this->used_at !== null) {
            return 'used';
        }

        return $this->expires_at->isPast() ? 'expired' : 'open';
    }
}
