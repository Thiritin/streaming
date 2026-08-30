<?php

namespace App\Support;

use App\Models\Emote;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * One account's whole record, as a zip.
 *
 * `account.json` is the complete thing in one file, which is what a program reads. The
 * same content is repeated as a CSV per kind under `data/`, which is what a person
 * opens: a year of chat in a JSON tree is unreadable and the same rows in a spreadsheet
 * are not. Anything the account uploaded is copied in whole under `files/`, since a
 * record of an emote is not the emote.
 *
 * Written to a temp file rather than streamed, because a zip's index is written last
 * and ZipArchive wants somewhere to seek.
 */
class AccountArchive
{
    /**
     * Build the archive and answer the path it was written to. The caller owns the
     * file - the download response deletes it after sending.
     */
    public static function build(User $user): string
    {
        $path = tempnam(sys_get_temp_dir(), 'account-export-');

        if ($path === false) {
            throw new RuntimeException('Could not open a temporary file for the account export.');
        }

        $data = AccountExport::for($user);

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not open the account export archive for writing.');
        }

        $zip->addFromString(
            'account.json',
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );

        foreach (self::tables($data) as $name => $rows) {
            if ($rows === []) {
                continue;
            }

            $zip->addFromString("data/{$name}.csv", self::csv($rows));
        }

        self::addUploads($zip, $user);

        $zip->close();

        return $path;
    }

    public static function filename(User $user): string
    {
        $name = preg_replace('/[^A-Za-z0-9]+/', '-', (string) $user->name);
        $name = trim(strtolower($name), '-') ?: 'account';

        return "{$name}-data-".now()->format('Y-m-d').'.zip';
    }

    /**
     * The export tree flattened into one table per file.
     *
     * Only the parts that are lists of like rows. The profile and the settings are a
     * handful of unrelated values and belong in the JSON alone; a two-line CSV of them
     * is worse than not having one.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    private static function tables(array $data): array
    {
        return [
            'chat-messages' => $data['chat_messages'],
            'comments' => $data['comments'],
            'comment-hearts' => $data['comment_hearts'],
            'comment-reports' => $data['comment_reports'],
            'watch-progress' => $data['watch_progress'],
            'followed-shows' => $data['followed_shows'],
            'favourite-emotes' => array_map(fn (string $name) => ['emote' => $name], $data['favourite_emotes']),
            'feedback' => $data['feedback'],
            'viewing-sessions' => $data['viewing_sessions'],
            'notifications-sent' => $data['notifications_sent'],
            'chat-bans' => $data['moderation']['chat_bans'],
            'timeouts' => $data['moderation']['timeouts'],
        ];
    }

    /**
     * Every row carries the same keys, so the first one is the header.
     */
    private static function csv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');

        // Excel reads a CSV as the local codepage unless it is told otherwise, which
        // turns every emoji and accented name in a chat backlog into mojibake.
        fwrite($handle, "\xEF\xBB\xBF");

        // The escape parameter is deprecated as of PHP 8.4 unless it is given, and an
        // empty one is the only value that writes RFC 4180 rather than PHP's backslash
        // dialect - which a spreadsheet reads wrong.
        fputcsv($handle, array_keys($rows[0]), ',', '"', '');

        foreach ($rows as $row) {
            fputcsv($handle, array_map(self::scalar(...), $row), ',', '"', '');
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    private static function scalar(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'yes' : 'no',
            is_array($value) => json_encode($value),
            default => (string) $value,
        };
    }

    /**
     * What the account uploaded. Emotes are the only thing a viewer puts on the site;
     * an avatar is the identity provider's own URL, not a file held here.
     *
     * An object that has gone missing from the bucket is skipped rather than failing
     * the export: the rest of the archive is still the account's record.
     */
    private static function addUploads(ZipArchive $zip, User $user): void
    {
        $emotes = Emote::where('uploaded_by_user_id', $user->id)->get();

        foreach ($emotes as $emote) {
            if (! $emote->s3_key) {
                continue;
            }

            try {
                if (! Storage::disk('s3')->exists($emote->s3_key)) {
                    continue;
                }

                $contents = Storage::disk('s3')->get($emote->s3_key);
            } catch (\Throwable) {
                continue;
            }

            if ($contents === null) {
                continue;
            }

            $extension = pathinfo($emote->s3_key, PATHINFO_EXTENSION) ?: 'png';

            $zip->addFromString("files/emotes/{$emote->name}.{$extension}", $contents);
        }
    }
}
