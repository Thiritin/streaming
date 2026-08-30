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
    public const PURPOSE_CHAT = 'chat';

    public const PURPOSE_VIEWER = 'viewer';

    protected $fillable = [
        'code',
        'purpose',
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
    public static function mint(?User $user = null, string $purpose = self::PURPOSE_CHAT): self
    {
        do {
            $code = Str::upper(Str::random(3).'-'.Str::random(3));
            $code = preg_replace('/[^A-Z0-9-]/', (string) random_int(2, 9), $code) ?? $code;
        } while (self::where('code', $code)->exists());

        return self::create([
            'code' => $code,
            'purpose' => $purpose,
            'created_by' => $user?->id,
            'expires_at' => now()->addMinutes((int) config('telegram.link_code_ttl_minutes', 30)),
        ]);
    }

    /**
     * A viewer's own code, minted from /settings. It attaches a Telegram account to the
     * user that made it and nothing else: pasting one into a control-room group must
     * never hand that group the panel's Start and End buttons, which is why the purpose
     * is stored rather than inferred from where the code turns up.
     */
    public static function mintForViewer(User $user): self
    {
        // One open code per viewer. Minting a second while the first is still good
        // leaves two valid codes for the same account with no way to tell which one is
        // on the screen being read from.
        self::where('created_by', $user->id)
            ->where('purpose', self::PURPOSE_VIEWER)
            ->whereNull('used_at')
            ->delete();

        return self::mint($user, self::PURPOSE_VIEWER);
    }

    /**
     * The viewer's current code, minting one only if there is none to reuse.
     *
     * The settings page needs a code before anything is pressed, because the connect
     * button is a link into Telegram carrying it. Minting a fresh one on every page
     * load would leave a trail of valid codes for one account.
     */
    public static function ensureForViewer(User $user): self
    {
        $existing = self::where('created_by', $user->id)
            ->where('purpose', self::PURPOSE_VIEWER)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();

        return $existing ?? self::mintForViewer($user);
    }

    public function isViewerCode(): bool
    {
        return $this->purpose === self::PURPOSE_VIEWER;
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
