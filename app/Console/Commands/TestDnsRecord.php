<?php

namespace App\Console\Commands;

use App\Services\DnsKeyService;
use Illuminate\Console\Command;

class TestDnsRecord extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'dns:test-record {action=create : create or delete} {--hostname=test : hostname prefix}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test DNS record creation and deletion';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $action = $this->argument('action');
        $hostnamePrefix = $this->option('hostname');
        $zone = config('dns.zone');
        $hostname = $hostnamePrefix . '.' . $zone;
        $testIp = '127.0.0.1'; // Using localhost IP for testing
        
        $this->info("DNS Record Test - Action: $action");
        $this->info("Hostname: $hostname");
        
        try {
            $dnsService = new DnsKeyService();
            
            if ($action === 'create') {
                $this->info("Creating DNS A record: $hostname -> $testIp");
                
                $commands = sprintf(
                    "update add %s %d A %s",
                    $hostname,
                    config('dns.ttl', 60),
                    $testIp
                );
                
                $result = $dnsService->executeNsupdate($commands);
                
                $this->info("✓ DNS record created successfully");
                $this->info("Command output: " . trim($result));
                
                // Wait a moment for DNS to propagate
                $this->info("\nWaiting 2 seconds for DNS propagation...");
                sleep(2);
                
                // Test with dig
                $this->info("\nVerifying with dig command:");
                $digCommand = "dig +short $hostname @" . config('dns.server');
                $this->info("Running: $digCommand");
                
                $digResult = shell_exec($digCommand);
                
                if ($digResult) {
                    $this->info("✓ DNS query result: " . trim($digResult));
                    if (trim($digResult) === $testIp) {
                        $this->info("✓ DNS record verified successfully!");
                    } else {
                        $this->warn("⚠ DNS record exists but IP doesn't match expected value");
                    }
                } else {
                    $this->warn("⚠ No DNS response yet. The record may still be propagating.");
                    $this->info("You can manually check with: dig $hostname @" . config('dns.server'));
                }
                
            } elseif ($action === 'delete') {
                $this->info("Deleting DNS A record: $hostname");
                
                $commands = sprintf(
                    "update delete %s %d A",
                    $hostname,
                    config('dns.ttl', 60)
                );
                
                $result = $dnsService->executeNsupdate($commands);
                
                $this->info("✓ DNS record deleted successfully");
                $this->info("Command output: " . trim($result));
                
                // Wait and verify deletion
                $this->info("\nWaiting 2 seconds for DNS propagation...");
                sleep(2);
                
                $this->info("\nVerifying deletion with dig command:");
                $digCommand = "dig +short $hostname @" . config('dns.server');
                $digResult = shell_exec($digCommand);
                
                if (!$digResult || trim($digResult) === '') {
                    $this->info("✓ DNS record successfully removed");
                } else {
                    $this->warn("⚠ DNS record still exists: " . trim($digResult));
                    $this->info("The deletion may still be propagating.");
                }
                
            } else {
                $this->error("Invalid action. Use 'create' or 'delete'");
                return Command::FAILURE;
            }
            
            return Command::SUCCESS;
            
        } catch (\Exception $e) {
            $this->error('Error: ' . $e->getMessage());
            $this->error('Stack trace: ' . $e->getTraceAsString());
            return Command::FAILURE;
        }
    }
}