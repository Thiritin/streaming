<?php

namespace App\Support\Manage;

use App\Enum\ServerStatusEnum;
use App\Enum\ServerTypeEnum;
use App\Enum\SourceStatusEnum;
use App\Enum\StreamStatusEnum;

/**
 * Single source of truth for how a domain state is presented: label, tone, glyph.
 *
 * The client never derives a colour from a raw value. It receives a tone name and
 * looks that up in the CSS token map, so the table, the badges and the status strip
 * cannot drift apart.
 *
 * Icon names are lucide names in kebab-case, resolved by ManageIcon.vue.
 */
final class Status
{
    public const LIVE = 'live';

    public const OK = 'ok';

    public const WARN = 'warn';

    public const IDLE = 'idle';

    public const DANGER = 'danger';

    public const INFO = 'info';

    /**
     * @return array{label: string, tone: string, icon: string|null}
     */
    public static function make(string $label, string $tone, ?string $icon = null): array
    {
        return ['label' => $label, 'tone' => $tone, 'icon' => $icon];
    }

    /**
     * Whether a show is meant to end up published. Planning, not a gate: see the
     * migration that added `shows.publish_plan`.
     *
     * @return array{label: string, tone: string, icon: string|null}
     */
    public static function publishPlan(?string $plan): array
    {
        return match ($plan) {
            'yes' => self::make('Yes', self::OK, 'circle-dot'),
            'no' => self::make('No', self::IDLE, 'circle-slash'),
            default => self::make('Undecided', self::WARN, 'circle-help'),
        };
    }

    /**
     * The onsite capture: the fallback copy made in the room. Only worth chasing once the
     * stream capture has failed, which is why the unset value reads as a question rather
     * than as a problem.
     *
     * @return array{label: string, tone: string, icon: string|null}
     */
    public static function onsiteStatus(?string $status): array
    {
        return match ($status) {
            'none' => self::make('None made', self::IDLE, 'minus'),
            'expected' => self::make('Expected', self::WARN, 'clock'),
            'received' => self::make('Received', self::OK, 'inbox'),
            'unusable' => self::make('Unusable', self::DANGER, 'circle-x'),
            default => self::make('Not checked', self::IDLE, 'circle-help'),
        };
    }

    /**
     * How the stream capture came back - what the uploader mirrored off the source.
     *
     * @return array{label: string, tone: string, icon: string|null}
     */
    public static function streamCondition(?string $condition): array
    {
        return match ($condition) {
            'ok' => self::make('OK', self::OK, 'circle-check'),
            'no_audio' => self::make('No audio', self::WARN, 'volume-x'),
            'no_video' => self::make('No video', self::WARN, 'video-off'),
            'incomplete' => self::make('Incomplete', self::WARN, 'triangle-alert'),
            'lost' => self::make('Lost', self::DANGER, 'circle-slash'),
            default => self::make('Unchecked', self::IDLE, 'circle-help'),
        };
    }

    /**
     * Where a show stands on actually having a recording, from Show::recordingState().
     *
     * The two captures are a fallback chain, so the states between "nothing" and "gone"
     * matter: a show whose onsite master is in hand is not missing, and one whose card is
     * still being chased is not lost.
     *
     * @return array{label: string, tone: string, icon: string|null}
     */
    public static function recordingState(string $state): array
    {
        return match ($state) {
            'published' => self::make('Published', self::OK, 'circle-check'),
            'ready' => self::make('Ready', self::INFO, 'film'),
            'draft' => self::make('Draft', self::WARN, 'scissors'),
            'failed' => self::make('Failed', self::DANGER, 'triangle-alert'),
            // The master is in hand; somebody has to import it.
            'onsite' => self::make('Onsite master', self::INFO, 'inbox'),
            // The stream capture failed and the room's copy has not turned up yet.
            'needs_onsite' => self::make('Needs onsite', self::WARN, 'search'),
            'missing' => self::make('Missing', self::DANGER, 'circle-x'),
            // Terminal: both captures are gone. Not a to-do, which is why it is not the
            // same red as Missing.
            'lost' => self::make('Lost', self::DANGER, 'circle-slash'),
            'skipped' => self::make('Not published', self::IDLE, 'minus'),
            default => self::make('Pending', self::IDLE, 'clock'),
        };
    }

    /**
     * @return array{label: string, tone: string, icon: string|null}
     */
    public static function show(?string $status): array
    {
        return match ($status) {
            'live' => self::make('Live', self::LIVE, 'signal'),
            'scheduled' => self::make('Scheduled', self::WARN, 'clock'),
            'ended' => self::make('Ended', self::IDLE, 'circle-check'),
            'cancelled' => self::make('Cancelled', self::DANGER, 'circle-x'),
            default => self::make((string) $status, self::IDLE, null),
        };
    }

    /**
     * @return array{label: string, tone: string, icon: string|null}
     */
    public static function source(SourceStatusEnum|string|null $status): array
    {
        $value = $status instanceof SourceStatusEnum ? $status->value : $status;

        return match ($value) {
            SourceStatusEnum::ONLINE->value => self::make('Online', self::LIVE, 'signal'),
            // Not lucide's `signal-zero`, which is the obvious pair for `signal` and
            // is unusable here: it is a single path, `M2 20h.01`, so its only ink is a
            // hairline dot in the bottom-left corner of a 24x24 box. At the 12px a
            // badge renders, that is a speck floating below the baseline, detached
            // from its own label. `signal` gets away with the same dot because its
            // bars fill the rest of the box. `minus` is `M5 12h14` - centred, neutral,
            // and reads as "nothing coming in" rather than as an error.
            SourceStatusEnum::OFFLINE->value => self::make('Offline', self::IDLE, 'minus'),
            SourceStatusEnum::ERROR->value => self::make('Error', self::DANGER, 'triangle-alert'),
            default => self::make((string) $value, self::IDLE, null),
        };
    }

    /**
     * @return array{label: string, tone: string, icon: string|null}
     */
    public static function server(ServerStatusEnum|string|null $status): array
    {
        $value = $status instanceof ServerStatusEnum ? $status->value : $status;

        return match ($value) {
            ServerStatusEnum::ACTIVE->value => self::make('Active', self::OK, 'circle-check'),
            ServerStatusEnum::PROVISIONING->value => self::make('Provisioning', self::WARN, 'loader'),
            ServerStatusEnum::DEPROVISIONING->value => self::make('Deprovisioning', self::DANGER, 'loader'),
            ServerStatusEnum::DELETED->value => self::make('Deleted', self::IDLE, 'circle-x'),
            ServerStatusEnum::ERROR->value => self::make('Error', self::DANGER, 'triangle-alert'),
            default => self::make((string) $value, self::IDLE, null),
        };
    }

    /**
     * @return array{label: string, tone: string, icon: string|null}
     */
    public static function serverType(ServerTypeEnum|string|null $type): array
    {
        $value = $type instanceof ServerTypeEnum ? $type->value : $type;

        return match ($value) {
            ServerTypeEnum::ORIGIN->value => self::make('Origin', self::WARN, 'radio-tower'),
            ServerTypeEnum::EDGE->value => self::make('Edge', self::OK, 'server'),
            default => self::make((string) $value, self::IDLE, null),
        };
    }

    /**
     * @return array{label: string, tone: string, icon: string|null}
     */
    public static function health(?string $health): array
    {
        return match ($health) {
            'healthy' => self::make('Healthy', self::OK, 'heart-pulse'),
            'unhealthy' => self::make('Unhealthy', self::DANGER, 'heart-crack'),
            default => self::make('Unknown', self::IDLE, 'circle-help'),
        };
    }

    /**
     * @return array{label: string, tone: string, icon: string|null}
     */
    public static function stream(StreamStatusEnum|string|null $status): array
    {
        $value = $status instanceof StreamStatusEnum ? $status->value : $status;

        return match ($value) {
            StreamStatusEnum::ONLINE->value => self::make('Online', self::LIVE, 'signal'),
            StreamStatusEnum::STARTING_SOON->value => self::make('Starting soon', self::WARN, 'clock'),
            StreamStatusEnum::PROVISIONING->value => self::make('Provisioning', self::WARN, 'loader'),
            StreamStatusEnum::TECHNICAL_ISSUE->value => self::make('Technical issue', self::DANGER, 'triangle-alert'),
            // See the note on source() above for why this is not `signal-zero`.
            StreamStatusEnum::OFFLINE->value => self::make('Offline', self::IDLE, 'minus'),
            default => self::make((string) $value, self::IDLE, null),
        };
    }

    /**
     * Where a viewer report has got to.
     *
     * @return array{label: string, tone: string, icon: string|null}
     */
    public static function feedback(?string $status): array
    {
        return match ($status) {
            'new' => self::make('New', self::WARN, 'sparkles'),
            'open' => self::make('Open', self::INFO, 'clock'),
            'resolved' => self::make('Resolved', self::OK, 'circle-check'),
            default => self::make((string) $status, self::IDLE, null),
        };
    }

    /**
     * Feedback from the top bar against a problem reported from a player. The tone
     * is the whole point of splitting them: one is a suggestion, the other is
     * somebody watching something broken right now.
     *
     * @return array{label: string, tone: string, icon: string|null}
     */
    public static function feedbackType(?string $type): array
    {
        return match ($type) {
            'issue' => self::make('Stream issue', self::DANGER, 'triangle-alert'),
            'feedback' => self::make('Feedback', self::INFO, 'message-square'),
            default => self::make((string) $type, self::IDLE, null),
        };
    }

    /**
     * Two-state badge, e.g. Auto/Manual or Restricted/Public.
     *
     * @return array{label: string, tone: string, icon: string|null}
     */
    public static function toggle(
        bool $state,
        string $trueLabel,
        string $falseLabel,
        string $trueTone = self::OK,
        string $falseTone = self::IDLE,
        ?string $trueIcon = null,
        ?string $falseIcon = null,
    ): array {
        return $state
            ? self::make($trueLabel, $trueTone, $trueIcon)
            : self::make($falseLabel, $falseTone, $falseIcon);
    }
}
