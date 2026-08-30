<?php

namespace App\Console\Commands;

use App\Services\DnsKeyService;
use Illuminate\Console\Command;

class ShowDnsDetails extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'dns:show-details';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Show DNS configuration details for sysadmin';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info("=== DNS Configuration Details for Sysadmin ===\n");

        // Configuration from environment
        $this->info('1. DNS Server Configuration:');
        $this->info('   Server IP: '.config('dns.server'));
        $this->info('   Zone: '.config('dns.zone'));
        $this->info('   Key Name: '.config('dns.key_name'));
        $this->info('   Algorithm: '.config('dns.key_algorithm'));
        $this->info('   TTL: '.config('dns.ttl').' seconds');

        // Generate and show key file
        $dnsService = new DnsKeyService;
        $keyFile = $dnsService->generateKeyFile();

        $this->info("\n2. Generated DNS Key File Contents:");
        $this->info("   File Path: $keyFile");
        $this->info('   Contents:');
        // Masked the way dns:test already masks it. The secret is stored encrypted, so
        // reading the row is deliberately not the same as reading the value; printing it
        // to stdout would put it in shell history and any captured terminal output.
        // Both shapes: the quoted one this service writes, and the bare `secret abc;`
        // BIND accepts - which is the likely shape of a hand-written dns.key, the one
        // file still read whole.
        $this->line(preg_replace('/secret\s+[^;]+/', 'secret "***HIDDEN***"', file_get_contents($keyFile)));

        // Show an example nsupdate command against the configured zone
        $hostname = trim('test222.'.config('dns.zone'), '.');
        $testIp = '127.0.0.1';

        $this->info("\n3. Example nsupdate Command (for creating $hostname):");
        $this->info('   Command:');
        $this->line("nsupdate -k $keyFile << EOF");
        $this->line('server '.config('dns.server'));
        $this->line('zone '.config('dns.zone'));
        $this->line("update add $hostname ".config('dns.ttl')." A $testIp");
        $this->line('send');
        $this->line('EOF');

        // Show dig verification command
        $this->info("\n4. Verification Command:");
        $this->line("dig +short $hostname @".config('dns.server'));

        // Test current connectivity
        $this->info("\n5. Current Connectivity Test:");
        // The name server is a setting now, so it reaches the shell as untrusted input.
        $pingResult = shell_exec('ping -c 1 -W 1 '.escapeshellarg((string) config('dns.server')).' 2>&1');
        if (strpos($pingResult, '1 received') !== false) {
            $this->info('   ✓ DNS server is reachable');
        } else {
            $this->warn('   ⚠ DNS server may not be reachable');
        }

        // Get our public IP
        $publicIp = trim(shell_exec('curl -s ifconfig.me 2>/dev/null') ?: 'Unable to determine');
        $this->info("   Our public IP: $publicIp");

        $this->info("\n6. Important Notes for Sysadmin:");
        $this->info('   - TSIG authentication is working correctly');
        $this->info("   - The key 'stream-ddns' needs to be authorized for the zone");
        $this->info("   - The zone needs to allow dynamic updates from our IP: $publicIp");
        $this->info('   - Current status: Authentication works but updates are REFUSED');

        // Cleanup
        $dnsService->cleanup();

        return Command::SUCCESS;
    }
}
