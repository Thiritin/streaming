<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DnsKeyService
{
    private ?string $keyFilePath = null;

    /**
     * Generate a temporary DNS key file from environment configuration
     *
     * @return string The path to the generated key file
     *
     * @throws \Exception
     */
    public function generateKeyFile(): string
    {
        // Check if a static dns.key exists first (backward compatibility)
        if (file_exists(base_path('dns.key'))) {
            return base_path('dns.key');
        }

        $keyName = config('dns.key_name');
        $algorithm = config('dns.key_algorithm');
        $secret = config('dns.key_secret');

        if (! $keyName || ! $algorithm || ! $secret) {
            throw new \Exception('DNS configuration is incomplete. Please check DNS_KEY_NAME, DNS_KEY_ALGORITHM, and DNS_KEY_SECRET environment variables.');
        }

        // Generate unique filename for this instance
        $filename = 'dns_key_'.Str::random(16).'.key';
        $relativePath = 'temp/'.$filename;

        // Ensure temp directory exists
        Storage::makeDirectory('temp');

        // Generate key file content
        $keyContent = sprintf(
            "key \"%s\" {\n\talgorithm %s;\n\tsecret \"%s\";\n};\n",
            $keyName,
            $algorithm,
            $secret
        );

        // Write the key file
        Storage::put($relativePath, $keyContent);

        // Get the absolute path
        $this->keyFilePath = Storage::path($relativePath);

        // Set proper permissions (600 - read/write for owner only)
        chmod($this->keyFilePath, 0600);

        return $this->keyFilePath;
    }

    /**
     * Execute nsupdate command with the generated key file
     *
     * @param  string  $commands  The nsupdate commands to execute
     * @return string The command output
     *
     * @throws \Exception
     */
    public function executeNsupdate(string $commands): string
    {
        $keyFile = $this->generateKeyFile();

        try {
            $server = config('dns.server');
            $zone = config('dns.zone');

            $fullCommand = sprintf(
                "nsupdate -v -k %s << EOF\nserver %s\nzone %s\n%s\nsend\nEOF",
                escapeshellarg($keyFile),
                $server,
                $zone,
                $commands
            );

            return ShellCommand::execute($fullCommand);
        } finally {
            $this->cleanup();
        }
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
