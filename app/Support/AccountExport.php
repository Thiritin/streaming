<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Everything one account has put into the site, as one array ready to be handed back
 * as JSON.
 *
 * Two rules shape what is in here. It is the viewer's own data, so anything belonging
 * to somebody else is left out: a moderator's name against a ban, the author of a
 * comment that was hearted, the reporter behind a report. And it is a viewer-facing
 * file, so it carries no source names - which room a stream came out of stays in
 * /manage - and no credential: the streamkey is a live key, not a record of anything.
 *
 * Read in whole rather than streamed. One account's chat backlog is thousands of short
 * rows at the very worst, and a chunked writer would buy nothing but a harder file to
 * get right.
 */
class AccountExport
{
    public static function for(User $user): array
    {
        return [
            'exported_at' => now()->toIso8601String(),
            'account' => self::account($user),
            'settings' => self::settings($user),
            'chat_messages' => self::chatMessages($user),
            'comments' => self::comments($user),
            'comment_hearts' => self::commentHearts($user),
            'comment_reports' => self::commentReports($user),
            'watch_progress' => self::watchProgress($user),
            'followed_shows' => self::followedShows($user),
            'favourite_emotes' => self::favouriteEmotes($user),
            'feedback' => self::feedback($user),
            'viewing_sessions' => self::viewingSessions($user),
            'notifications_sent' => self::notificationsSent($user),
            'moderation' => self::moderation($user),
        ];
    }

    private static function account(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'avatar' => $user->avatar,
            'identity_subject' => $user->sub,
            'registration_id' => $user->reg_id,
            'created_at' => $user->created_at?->toIso8601String(),
            'roles' => $user->roles()->pluck('name')->all(),
        ];
    }

    private static function settings(User $user): array
    {
        return [
            'feature_preferences' => $user->feature_preferences ?? [],
            'notification_channels' => $user->notification_channels ?? [],
            'notify_shows_live' => $user->notify_shows_live,
            'notify_recordings' => $user->notify_recordings,
            'telegram_username' => $user->telegram_username,
            'telegram_linked_at' => $user->telegram_linked_at?->toIso8601String(),
        ];
    }

    private static function chatMessages(User $user): array
    {
        return DB::table('messages')
            ->where('user_id', $user->id)
            ->orderBy('id')
            ->get(['message', 'type', 'created_at', 'deleted_at'])
            ->map(fn ($row) => [
                'message' => $row->message,
                'type' => $row->type,
                'sent_at' => self::stamp($row->created_at),
                'deleted_at' => self::stamp($row->deleted_at),
            ])
            ->all();
    }

    private static function comments(User $user): array
    {
        return DB::table('recording_comments')
            ->leftJoin('recordings', 'recordings.id', '=', 'recording_comments.recording_id')
            ->where('recording_comments.user_id', $user->id)
            ->orderBy('recording_comments.id')
            ->get([
                'recordings.title as recording',
                'recording_comments.body',
                'recording_comments.parent_id',
                'recording_comments.created_at',
                'recording_comments.edited_at',
                'recording_comments.hidden_at',
            ])
            ->map(fn ($row) => [
                'recording' => $row->recording,
                'body' => $row->body,
                'is_reply' => $row->parent_id !== null,
                'posted_at' => self::stamp($row->created_at),
                'edited_at' => self::stamp($row->edited_at),
                'hidden_at' => self::stamp($row->hidden_at),
            ])
            ->all();
    }

    private static function commentHearts(User $user): array
    {
        return DB::table('recording_comment_hearts')
            ->leftJoin('recording_comments', 'recording_comments.id', '=', 'recording_comment_hearts.recording_comment_id')
            ->leftJoin('recordings', 'recordings.id', '=', 'recording_comments.recording_id')
            ->where('recording_comment_hearts.user_id', $user->id)
            ->orderBy('recording_comment_hearts.id')
            ->get(['recordings.title as recording', 'recording_comment_hearts.created_at'])
            ->map(fn ($row) => [
                'recording' => $row->recording,
                'hearted_at' => self::stamp($row->created_at),
            ])
            ->all();
    }

    private static function commentReports(User $user): array
    {
        return DB::table('recording_comment_reports')
            ->where('user_id', $user->id)
            ->orderBy('id')
            ->get(['message', 'created_at', 'resolved_at'])
            ->map(fn ($row) => [
                'message' => $row->message,
                'reported_at' => self::stamp($row->created_at),
                'resolved_at' => self::stamp($row->resolved_at),
            ])
            ->all();
    }

    private static function watchProgress(User $user): array
    {
        return DB::table('recording_progress')
            ->leftJoin('recordings', 'recordings.id', '=', 'recording_progress.recording_id')
            ->where('recording_progress.user_id', $user->id)
            ->orderBy('recording_progress.id')
            ->get([
                'recordings.title as recording',
                'recording_progress.position',
                'recording_progress.duration',
                'recording_progress.completed',
                'recording_progress.updated_at',
            ])
            ->map(fn ($row) => [
                'recording' => $row->recording,
                'position_seconds' => $row->position,
                'duration_seconds' => $row->duration,
                'completed' => (bool) $row->completed,
                'updated_at' => self::stamp($row->updated_at),
            ])
            ->all();
    }

    private static function followedShows(User $user): array
    {
        return DB::table('show_subscriptions')
            ->leftJoin('shows', 'shows.id', '=', 'show_subscriptions.show_id')
            ->where('show_subscriptions.user_id', $user->id)
            ->orderBy('show_subscriptions.id')
            ->get(['shows.title as show', 'show_subscriptions.created_at'])
            ->map(fn ($row) => [
                'show' => $row->show,
                'followed_at' => self::stamp($row->created_at),
            ])
            ->all();
    }

    private static function favouriteEmotes(User $user): array
    {
        return DB::table('user_emote_favorites')
            ->leftJoin('emotes', 'emotes.id', '=', 'user_emote_favorites.emote_id')
            ->where('user_emote_favorites.user_id', $user->id)
            ->orderBy('user_emote_favorites.id')
            ->pluck('emotes.name')
            ->filter()
            ->values()
            ->all();
    }

    private static function feedback(User $user): array
    {
        return DB::table('feedback_reports')
            ->where('user_id', $user->id)
            ->orderBy('id')
            ->get(['type', 'message', 'telegram', 'url', 'status', 'created_at'])
            ->map(fn ($row) => [
                'type' => $row->type,
                'message' => $row->message,
                'telegram' => $row->telegram,
                'page' => $row->url,
                'status' => $row->status,
                'sent_at' => self::stamp($row->created_at),
            ])
            ->all();
    }

    /**
     * When this account was watching, and for how long. Which channel it was is left
     * out on purpose - source names are a /manage thing.
     */
    private static function viewingSessions(User $user): array
    {
        return DB::table('source_users')
            ->where('user_id', $user->id)
            ->orderBy('id')
            ->get(['joined_at', 'left_at', 'ip_address', 'user_agent'])
            ->map(fn ($row) => [
                'joined_at' => self::stamp($row->joined_at),
                'left_at' => self::stamp($row->left_at),
                'ip_address' => $row->ip_address,
                'user_agent' => $row->user_agent,
            ])
            ->all();
    }

    private static function notificationsSent(User $user): array
    {
        return DB::table('notification_deliveries')
            ->where('user_id', $user->id)
            ->orderBy('id')
            ->get(['type', 'channel', 'status', 'sent_at'])
            ->map(fn ($row) => [
                'type' => $row->type,
                'channel' => $row->channel,
                'status' => $row->status,
                'sent_at' => self::stamp($row->sent_at),
            ])
            ->all();
    }

    /**
     * What has been ruled against this account. The moderator behind each one is
     * somebody else's data and is not in here.
     */
    private static function moderation(User $user): array
    {
        return [
            'chat_bans' => DB::table('chat_bans')
                ->where('user_id', $user->id)
                ->orderBy('id')
                ->get(['reason', 'expires_at', 'lifted_at', 'created_at'])
                ->map(fn ($row) => [
                    'reason' => $row->reason,
                    'expires_at' => self::stamp($row->expires_at),
                    'lifted_at' => self::stamp($row->lifted_at),
                    'issued_at' => self::stamp($row->created_at),
                ])
                ->all(),
            'timeouts' => DB::table('timeouts')
                ->where('user_id', $user->id)
                ->orderBy('id')
                ->get(['reason', 'expires_at', 'created_at'])
                ->map(fn ($row) => [
                    'reason' => $row->reason,
                    'expires_at' => self::stamp($row->expires_at),
                    'issued_at' => self::stamp($row->created_at),
                ])
                ->all(),
        ];
    }

    /**
     * Query-builder rows come back with driver-shaped timestamps - a string on MySQL, a
     * string with microseconds on Postgres - so every one goes through here.
     */
    private static function stamp(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse($value)->toIso8601String();
    }
}
