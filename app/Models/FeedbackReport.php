<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing a viewer told us. See the create migration.
 */
class FeedbackReport extends Model
{
    use HasFactory;

    public const TYPE_FEEDBACK = 'feedback';

    public const TYPE_ISSUE = 'issue';

    public const STATUS_NEW = 'new';

    public const STATUS_OPEN = 'open';

    public const STATUS_RESOLVED = 'resolved';

    protected $fillable = [
        'type',
        'status',
        'user_id',
        'telegram',
        'message',
        'show_id',
        'source_id',
        'url',
        'user_agent',
        'ip',
        'diagnostics',
    ];

    protected $casts = [
        'diagnostics' => 'array',
        'handled_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function show(): BelongsTo
    {
        return $this->belongsTo(Show::class);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    public function scopeUnresolved(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_NEW, self::STATUS_OPEN]);
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_NEW);
    }

    /**
     * Fold the ways a handle gets typed onto one string: with or without the @, with
     * or without a t.me/ URL around it. Stored bare, shown with the @.
     */
    public static function normalizeTelegram(?string $handle): ?string
    {
        if ($handle === null) {
            return null;
        }

        $bare = trim($handle);
        $bare = preg_replace('#^(https?://)?(t\.me|telegram\.me)/#i', '', $bare) ?? $bare;
        $bare = ltrim($bare, '@ ');

        return $bare === '' ? null : $bare;
    }

    public function telegramHandle(): ?string
    {
        return $this->telegram ? '@'.$this->telegram : null;
    }

    public function telegramUrl(): ?string
    {
        return $this->telegram ? 'https://t.me/'.$this->telegram : null;
    }

    /**
     * Who to go back to. A signed-in report carries an account; a guest report is
     * only ever reachable through the handle they chose to leave.
     */
    public function reporterName(): string
    {
        return $this->user?->name ?? ($this->telegramHandle() ?? 'Guest');
    }

    /**
     * First line of the message, for a table cell that has one line to give.
     */
    public function excerpt(int $length = 120): string
    {
        $flat = trim(preg_replace('/\s+/', ' ', $this->message) ?? $this->message);

        return mb_strlen($flat) > $length ? mb_substr($flat, 0, $length - 1).'…' : $flat;
    }

    /**
     * The diagnostics blob flattened to `label => value` pairs, so /manage can print
     * it without knowing which keys the client happened to send.
     *
     * @return array<int, array{group: string, rows: array<int, array{label: string, value: string}>}>
     */
    public function diagnosticGroups(): array
    {
        $groups = [];

        foreach ($this->diagnostics ?? [] as $key => $value) {
            if (is_array($value)) {
                $rows = [];

                foreach ($value as $childKey => $childValue) {
                    $rows[] = [
                        'label' => self::label((string) $childKey),
                        'value' => self::display($childValue),
                    ];
                }

                $groups[] = ['group' => self::label((string) $key), 'rows' => $rows];

                continue;
            }

            $groups['general'] ??= ['group' => 'General', 'rows' => []];
            $groups['general']['rows'][] = [
                'label' => self::label((string) $key),
                'value' => self::display($value),
            ];
        }

        return array_values($groups);
    }

    private static function label(string $key): string
    {
        return str($key)->headline()->toString();
    }

    private static function display(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if (is_array($value)) {
            return implode(', ', array_map(fn ($item) => self::display($item), $value));
        }

        return (string) $value;
    }
}
