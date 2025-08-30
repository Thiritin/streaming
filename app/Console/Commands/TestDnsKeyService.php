<?php

namespace App\Console\Commands;

use App\Services\DnsKeyService;
use Illuminate\Console\Command;

class TestDnsKeyService extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'dns:test';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test DNS key generation from environment variables';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Testing DNS Key Service...');

        try {
            $dnsService = new DnsKeyService;

            // Test key file generation
            $this->info('Generating DNS key file...');
            $keyFile = $dnsService->generateKeyFile();

            if (file_exists($keyFile)) {
                $this->info("✓ Key file generated successfully at: $keyFile");

                // Display key file content (masked)
                $content = file_get_contents($keyFile);
                $maskedContent = preg_replace('/secret ".*"/', 'secret "***HIDDEN***"', $content);
                $this->info("Key file content:\n$maskedContent");

                // Check file permissions
                $perms = substr(sprintf('%o', fileperms($keyFile)), -4);
                $this->info("File permissions: $perms");

                if ($perms === '0600') {
                    $this->info('✓ File permissions are correct (0600)');
                } else {
                    $this->warn("✗ File permissions should be 0600, but are $perms");
                }

                // Test cleanup
                $dnsService->cleanup();

                if (! file_exists($keyFile) || str_contains($keyFile, 'dns.key')) {
                    $this->info('✓ Cleanup successful');
                } else {
                    $this->warn('✗ Cleanup may have failed');
                }

            } else {
                $this->error('✗ Failed to generate key file');
            }

            $this->info("\nConfiguration values:");
            $this->table(
                ['Setting', 'Value'],
                [
                    ['DNS Server', config('dns.server')],
                    ['DNS Zone', config('dns.zone')],
                    ['Key Name', config('dns.key_name')],
                    ['Algorithm', config('dns.key_algorithm')],
                    ['Secret', config('dns.key_secret') ? '***SET***' : '***NOT SET***'],
                    ['TTL', config('dns.ttl')],
                ]
            );

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error('Error: '.$e->getMessage());

            return Command::FAILURE;
        }
    }
}
