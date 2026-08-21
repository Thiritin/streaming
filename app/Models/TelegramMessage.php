<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A message the bot has posted and may still edit. See the create migration.
 */
class TelegramMessage extends Model
{
    public const KIND_SHOW = 'show';

    public const KIND_FEEDBACK = 'feedback';

    public const KIND_RECORDING = 'recording';

    public const STATE_UPCOMING = 'upcoming';

    public const STATE_LIVE = 'live';

    /** The End button has been pressed once and is waiting for the confirmation. */
    public const STATE_CONFIRM_END = 'confirm_end';

    public const STATE_CLOSED = 'closed';

    protected $fillable = [
        'telegram_chat_id',
        'message_id',
        'kind',
        'subject_id',
        'state',
    ];

    public function chat(): BelongsTo
    {
        return $this->belongsTo(TelegramChat::class, 'telegram_chat_id');
    }
}
