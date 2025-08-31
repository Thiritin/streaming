<?php

namespace App\Console\Commands;

use Aws\S3\Exception\S3Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class TestS3Storage extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:s3';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test S3 storage operations (upload, read, delete)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🚀 Starting S3 Storage Test...');
        $this->newLine();
        
        // Display current configuration
        $this->info('📋 Current S3 Configuration:');
        $this->table(
            ['Setting', 'Value'],
            [
                ['Endpoint', config('filesystems.disks.s3.endpoint')],
                ['Bucket', config('filesystems.disks.s3.bucket')],
                ['Region', config('filesystems.disks.s3.region')],
                ['Path Style', config('filesystems.disks.s3.use_path_style_endpoint') ? 'Yes' : 'No'],
                ['URL', config('filesystems.disks.s3.url') ?: 'Not set'],
                ['Key ID', substr(config('filesystems.disks.s3.key'), 0, 10) . '...'],
            ]
        );
        $this->newLine();

        // Test basic connection first
        $this->info('🔌 Testing S3 Connection...');
        
        try {
            $disk = Storage::disk('s3');
            
            // Try to list files in root to test connection
            $files = $disk->files('/');
            $this->info('✅ Successfully connected to S3!');
            $this->line('Found ' . count($files) . ' files in root directory');
            
        } catch (S3Exception $e) {
            $this->error('❌ S3 Connection Error: ' . $e->getAwsErrorMessage());
            $this->line('Error Code: ' . $e->getAwsErrorCode());
            $this->line('Request ID: ' . $e->getAwsRequestId());
            return 1;
        } catch (\Exception $e) {
            $this->error('❌ Connection Error: ' . $e->getMessage());
            return 1;
        }

        $this->newLine();
        
        // Now test file operations
        $testFileName = 'test-' . Str::random(10) . '.txt';
        $testContent = "Test file created at " . now()->toDateTimeString();

        try {
            // Test 1: Upload a file
            $this->info('📤 Test 1: Uploading file to S3...');
            $this->line('File: ' . $testFileName);
            
            $uploaded = false;
            
            // Method 1: Simple put
            try {
                $uploaded = $disk->put($testFileName, $testContent);
                if ($uploaded) {
                    $this->info('✅ File uploaded successfully using put()');
                }
            } catch (\Exception $e) {
                $this->warn('put() failed: ' . $e->getMessage());
            }
            
            // If first method failed, try alternative
            if (!$uploaded) {
                try {
                    // Method 2: Using Flysystem Config object
                    $adapter = $disk->getAdapter();
                    $config = new \League\Flysystem\Config([]);
                    $adapter->write($testFileName, $testContent, $config);
                    $uploaded = true;
                    $this->info('✅ File uploaded successfully using adapter');
                } catch (\Exception $e) {
                    $this->error('Adapter write failed: ' . $e->getMessage());
                    throw $e;
                }
            }
            
            if (!$uploaded) {
                $this->error('❌ All upload methods failed');
                return 1;
            }

            // Test 2: Check if file exists
            $this->newLine();
            $this->info('🔍 Test 2: Checking if file exists...');
            
            try {
                $exists = $disk->exists($testFileName);
                if ($exists) {
                    $this->info('✅ File exists on S3');
                } else {
                    $this->error('❌ File not found on S3');
                    return 1;
                }
            } catch (\Exception $e) {
                $this->error('Error checking file: ' . $e->getMessage());
            }

            // Test 3: Read the file
            $this->newLine();
            $this->info('📖 Test 3: Reading file from S3...');
            
            try {
                $readContent = $disk->get($testFileName);
                if ($readContent === $testContent) {
                    $this->info('✅ File content matches original');
                    $this->line('Content: ' . $readContent);
                } else {
                    $this->warn('⚠️ File content differs');
                    $this->line('Original: ' . $testContent);
                    $this->line('Read: ' . $readContent);
                }
            } catch (\Exception $e) {
                $this->error('Error reading file: ' . $e->getMessage());
            }

            // Test 4: Get file URL
            $this->newLine();
            $this->info('🔗 Test 4: Getting file URL...');
            
            try {
                $url = $disk->url($testFileName);
                $this->info('File URL: ' . $url);
            } catch (\Exception $e) {
                $this->warn('URL generation issue: ' . $e->getMessage());
            }
            
            // Try temporary URL separately
            try {
                $tempUrl = $disk->temporaryUrl($testFileName, now()->addMinutes(5));
                $this->info('Temporary URL (5 min): ' . $tempUrl);
            } catch (\Exception $e) {
                $this->warn('Temporary URL not supported or configured: ' . get_class($e));
            }

            // Test 5: Delete the file
            $this->newLine();
            $this->info('🗑️ Test 5: Deleting file from S3...');
            
            try {
                $deleted = $disk->delete($testFileName);
                if ($deleted) {
                    $this->info('✅ File deleted successfully');
                } else {
                    $this->error('❌ Failed to delete file');
                }
            } catch (\Exception $e) {
                $this->error('Error deleting file: ' . $e->getMessage());
            }

            // Verify deletion
            $this->newLine();
            $this->info('🔍 Verifying deletion...');
            
            try {
                $stillExists = $disk->exists($testFileName);
                if (!$stillExists) {
                    $this->info('✅ File successfully removed from S3');
                } else {
                    $this->error('❌ File still exists after deletion');
                }
            } catch (\Exception $e) {
                $this->warn('Error verifying deletion: ' . $e->getMessage());
            }

            $this->newLine();
            $this->info('🎉 S3 storage tests completed!');
            
            return 0;

        } catch (S3Exception $e) {
            $this->newLine();
            $this->error('❌ S3 Error occurred:');
            $this->error('Message: ' . $e->getAwsErrorMessage());
            $this->error('Code: ' . $e->getAwsErrorCode());
            $this->error('Type: ' . $e->getAwsErrorType());
            $this->error('Request ID: ' . $e->getAwsRequestId());
            
            return 1;
        } catch (\Exception $e) {
            $this->newLine();
            $this->error('❌ Test Failed with error:');
            $this->error($e->getMessage());
            $this->line('Class: ' . get_class($e));
            $this->line('File: ' . $e->getFile());
            $this->line('Line: ' . $e->getLine());
            
            return 1;
        }
    }
}