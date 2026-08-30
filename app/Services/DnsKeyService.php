<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The TSIG half of RFC2136 updates: a key file on disk and one nsupdate transaction
 * run against it.
 *
 * Values default to config/dns.php, which the settings pane overlays, so a caller that
 * has already resolved them can hand them over instead.
 */
class DnsKeyService
{
    /**
     * The key file an installation may have been running on since before any of this
     * was settable. It is read only when nothing is configured - see staticKeyFile().
     */
    public const STATIC_KEY_FILE = 'dns.key';

    /** What BIND will accept, and what the pane offers. */
    public const ALGORITHMS = ['hmac-sha256', 'hmac-sha512', 'hmac-sha1', 'hmac-md5'];

    private ?string $keyFilePath = null;

    public function __construct(
        private readonly ?string $keyName = null,
        private readonly ?string $algorithm = null,
        private readonly ?string $secret = null,
        private readonly ?string $server = null,
        private readonly ?string $zone = null,
    ) {}

    /**
     * The key file at the application root, when there is one and nothing is saved.
     *
     * It used to be preferred unconditionally, before any config was read at all, which
     * meant a secret typed into the panel changed nothing on exactly the machines that
     * had been running longest - no error, no log line. Now it is the fallback rather
     * than the winner: a configured secret is used, and the file only stands in for one
     * that was never set. Its use is logged and reported by the driver's check, so the
     * state is visible from the panel instead of being a silent lie.
     */
    public function staticKeyFile(): ?string
    {
        if ($this->secret() !== null && $this->secret() !== '') {
            return null;
        }

        $path = base_path(self::STATIC_KEY_FILE);

        return file_exists($path) ? $path : null;
    }

    /**
     * Generate a temporary DNS key file from the configured TSIG credentials.
     *
     * @return string The path to the key file
     *
     * @throws \Exception
     */
    public function generateKeyFile(): string
    {
        if ($static = $this->staticKeyFile()) {
            Log::warning('No TSIG secret is saved; falling back to the key file at '.$static);

            return $static;
        }

        $keyName = $this->keyName ?? config('dns.key_name');
        $algorithm = $this->algorithm ?? config('dns.key_algorithm');
        $secret = $this->secret();

        if (! $keyName || ! $algorithm || ! $secret) {
            throw new \Exception('DNS configuration is incomplete. Set the key name, algorithm and secret.');
        }

        // A TSIG secret is base64. It is written into `secret "%s";`, so a double quote
        // closes the clause early and whatever follows parses as further key config -
        // the one value on this path that had no shape at all.
        if (! preg_match('/^[A-Za-z0-9+\/=]+$/', $secret)) {
            throw new \InvalidArgumentException('The TSIG secret is not base64.');
        }

        self::requireName($keyName, 'key name');

        if (! in_array($algorithm, self::ALGORITHMS, true)) {
            throw new \InvalidArgumentException("Unsupported TSIG algorithm: {$algorithm}");
        }

        $relativePath = 'temp/dns_key_'.Str::random(16).'.key';

        $disk = Storage::disk('local');

        if (! $disk->exists('temp')) {
            $disk->makeDirectory('temp');
        }

        $written = $disk->put($relativePath, sprintf(
            "key \"%s\" {\n\talgorithm %s;\n\tsecret \"%s\";\n};\n",
            $keyName,
            $algorithm,
            $secret,
        ));

        if (! $written) {
            throw new \Exception('Failed to write DNS key file to local storage');
        }

        $this->keyFilePath = $disk->path($relativePath);

        clearstatcache(true, $this->keyFilePath);

        if (! file_exists($this->keyFilePath)) {
            throw new \Exception("DNS key file does not exist after writing: {$this->keyFilePath}");
        }

        // 600 - read/write for owner only.
        if (! @chmod($this->keyFilePath, 0600)) {
            throw new \Exception("Failed to set permissions on DNS key file: {$this->keyFilePath}");
        }

        return $this->keyFilePath;
    }

    /**
     * Execute one nsupdate transaction with the generated key file.
     *
     * @param  string  $commands  The nsupdate commands, newline separated
     * @return string The command output
     *
     * @throws \Exception
     */
    public function executeNsupdate(string $commands): string
    {
        $server = self::requireName($this->server ?? config('dns.server'), 'name server');
        $zone = self::requireName($this->zone ?? config('dns.zone'), 'zone');

        $keyFile = $this->generateKeyFile();

        try {
            // The script goes to stdin, not into a heredoc on the command line. An
            // unquoted heredoc is expanded by the shell before nsupdate sees a byte of
            // it, so a `$(...)` anywhere in a zone, a name server or a hostname - all of
            // them settings or form fields now - was a command run as the queue user.
            // escapeshellarg is not the fix here: nsupdate reads its own input, and a
            // quoted argument would land in the zone as literal quote marks.
            return ShellCommand::execute(
                'nsupdate -v -k '.escapeshellarg($keyFile),
                "server {$server}\nzone {$zone}\n{$commands}\nsend\n",
            );
        } finally {
            $this->cleanup();
        }
    }

    /**
     * A hostname or an address, and nothing that could be read as anything else.
     *
     * Belt to the registry's braces: these arrive from the settings table and from the
     * test button's request body, and validation on the way in is not the same promise
     * as validation at the point of use.
     */
    public static function requireName(mixed $value, string $what): string
    {
        $value = trim((string) $value);

        if ($value === '' || ! preg_match('/^[A-Za-z0-9]([A-Za-z0-9._:-]*[A-Za-z0-9])?$/', $value)) {
            throw new \InvalidArgumentException("The DNS {$what} is not a hostname or an address.");
        }

        return $value;
    }

    private function secret(): ?string
    {
        $secret = $this->secret ?? config('dns.key_secret');

        return is_string($secret) ? $secret : null;
    }

    /**
     * Clean up the temporary key file
     */
    public function cleanup(): void
    {
        if ($this->keyFilePath &&
            file_exists($this->keyFilePath) &&
            str_contains($this->keyFilePath, '/temp/dns_key_')) {
            unlink($this->keyFilePath);
            $this->keyFilePath = null;
        }
    }

    /**
     * Destructor to ensure cleanup
     */
    public function __destruct()
    {
        $this->cleanup();
    }
}
