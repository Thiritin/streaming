<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Proves a set of bucket credentials actually works, before an operator commits them.
 *
 * Three stages, in this order, because each one fails differently and the answer to
 * "what is wrong" is different for each: the credentials are refused, the bucket is not
 * there, or the bucket is there and read-only. The last is the one worth the round trip -
 * a bucket that lists happily and refuses a PUT looks healthy from every other angle, and
 * the archive's whole job is writing.
 *
 * The disk is built from the values handed in rather than from config, so a test of
 * unsaved values cannot leave a half-configured disk behind for the rest of the process:
 * Storage::build() returns an instance and registers nothing.
 */
final class ArchiveProbe
{
    /**
     * Named so a leftover is obviously this button's rather than a recording.
     */
    private const PREFIX = '_probe/settings-test-';

    /**
     * @param  array<string, mixed>  $credentials
     * @return array{ok: bool, stage: ?string, message: ?string}
     */
    public static function run(array $credentials): array
    {
        try {
            $disk = Storage::build([
                'driver' => 's3',
                'key' => $credentials['key'] ?? null,
                'secret' => $credentials['secret'] ?? null,
                'region' => $credentials['region'] ?? null,
                'bucket' => $credentials['bucket'] ?? null,
                'endpoint' => $credentials['endpoint'] ?? null,
                'use_path_style_endpoint' => (bool) ($credentials['path_style'] ?? false),
                'throw' => true,
            ]);
        } catch (Throwable $e) {
            return self::fail('credentials', $e);
        }

        $path = self::PREFIX.Str::random(12).'.txt';
        $body = 'settings test '.now()->toIso8601String();

        // Reaching the bucket at all. Bad keys and a missing bucket both surface here,
        // and they are told apart by what the provider called it.
        try {
            $disk->fileExists($path);
        } catch (Throwable $e) {
            return self::fail(self::reachStage($e), $e);
        }

        try {
            $disk->write($path, $body);
        } catch (Throwable $e) {
            return self::fail('write', $e);
        }

        try {
            $read = $disk->get($path);
        } catch (Throwable $e) {
            self::cleanUp($disk, $path);

            return self::fail('read', $e);
        }

        if ($read !== $body) {
            self::cleanUp($disk, $path);

            return ['ok' => false, 'stage' => 'read', 'message' => 'What came back did not match what went up.'];
        }

        try {
            $disk->delete($path);
        } catch (Throwable $e) {
            return self::fail('delete', $e);
        }

        return ['ok' => true, 'stage' => null, 'message' => null];
    }

    /**
     * Whether a failed first touch was the credentials or the bucket. The provider says
     * which; anything it does not name is reported as the credentials, because that is
     * the commoner mistake and the message carries the detail either way.
     */
    private static function reachStage(Throwable $e): string
    {
        $text = strtolower(self::message($e));

        foreach (['nosuchbucket', 'does not exist', 'not found', '404'] as $needle) {
            if (str_contains($text, $needle)) {
                return 'bucket';
            }
        }

        return 'credentials';
    }

    /**
     * A best-effort tidy-up: the probe object must not survive a failed test, but a
     * delete that fails on top of an already-failing test has nothing to add.
     */
    private static function cleanUp(mixed $disk, string $path): void
    {
        try {
            $disk->delete($path);
        } catch (Throwable) {
            // Nothing to do about it, and nothing worth saying.
        }
    }

    /**
     * @return array{ok: bool, stage: string, message: string}
     */
    private static function fail(string $stage, Throwable $e): array
    {
        return ['ok' => false, 'stage' => $stage, 'message' => self::message($e)];
    }

    /**
     * The provider's own sentence, on one line and bounded. The deepest exception is the
     * one that says something: the layers above it only say that the layer below failed.
     */
    private static function message(Throwable $e): string
    {
        while ($e->getPrevious() !== null) {
            $e = $e->getPrevious();
        }

        $message = trim(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '');

        // AWS appends its own diagnostics after the sentence; the sentence is the part
        // an operator can act on.
        $message = Str::before($message, ' (Status Code:');
        $message = Str::before($message, ' HTTP/1.1');

        return Str::limit($message, 200) ?: 'No reason given.';
    }
}
