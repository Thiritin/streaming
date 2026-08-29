<?php

namespace App\Support;

use App\Models\Server;
use Illuminate\Support\Str;

/**
 * The plaintext a streaming server presents back to the app.
 *
 * Only the hashes are stored, so the render that mints a pair is the one and only chance
 * to read it. Between the rotate that mints it and the page that shows it, it is parked
 * on the operator's own session - never in the database, a log line, a job payload or a
 * command's output.
 */
final class ServerCredentials
{
    public function __construct(
        public readonly string $sharedSecret,
        public readonly string $deployToken,
    ) {}

    public static function mint(): self
    {
        return new self(Str::random(48), Str::random(48));
    }

    /**
     * Hold a freshly minted pair for the operator who asked for it.
     *
     * Silently does nothing outside a request with a session - a queue job that mints
     * credentials for a box about to boot has nobody to show them to.
     */
    public static function remember(Server $server, self $credentials): void
    {
        $request = request();

        if (! $request->hasSession()) {
            return;
        }

        $request->session()->put(self::key($server), [
            'shared_secret' => $credentials->sharedSecret,
            'deploy_token' => $credentials->deployToken,
        ]);
    }

    public static function recall(Server $server): ?self
    {
        $request = request();

        if (! $request->hasSession()) {
            return null;
        }

        $stored = $request->session()->get(self::key($server));

        if (! is_array($stored)) {
            return null;
        }

        $sharedSecret = $stored['shared_secret'] ?? null;
        $deployToken = $stored['deploy_token'] ?? null;

        if (! is_string($sharedSecret) || ! is_string($deployToken)) {
            return null;
        }

        return new self($sharedSecret, $deployToken);
    }

    private static function key(Server $server): string
    {
        return "server_credentials.{$server->id}";
    }
}
