<?php

namespace Tests\Feature\Manage;

use App\Models\BrandingSetting;
use App\Support\Manage\Settings;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\SessionKey;
use League\Flysystem\UnableToWriteFile;
use Tests\Concerns\CreatesManageUsers;
use Tests\TestCase;

/**
 * The Test button on the archive storage pane: what it proves, what it says when each
 * stage fails, and that it leaves nothing behind.
 */
class ArchiveStorageTestTest extends TestCase
{
    use CreatesManageUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createManageUsers();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function test_(array $overrides = []): TestResponse
    {
        return $this->actingAs($this->admin)
            ->from(route('manage.settings.group', 'storage'))
            ->post(route('manage.settings.storage.test'), $overrides + [
                'endpoint' => 'https://s3.example.test',
                'bucket' => 'recordings',
                'region' => 'eu-central-1',
                'key' => 'access-key',
                'secret' => 'secret-key',
                'path_style' => true,
            ]);
    }

    /**
     * A real filesystem for the probe to write to, standing in for the bucket.
     *
     * Storage::fake() swaps the named disks, and the probe deliberately builds its own
     * from the posted values, so the disk it builds is what has to be stood in for.
     */
    private function bucket(): Filesystem
    {
        $root = storage_path('framework/testing/archive-probe');

        File::deleteDirectory($root);

        $disk = app(FilesystemManager::class)->build(['driver' => 'local', 'root' => $root]);

        Storage::shouldReceive('build')->once()->andReturn($disk);

        return $disk;
    }

    /**
     * @return array<string, mixed>
     */
    private function toast(): array
    {
        return session(SessionKey::FlashData->value)['toast'] ?? [];
    }

    public function test_a_bucket_that_writes_reads_and_deletes_reports_success(): void
    {
        $this->bucket();

        $this->test_()->assertRedirect(route('manage.settings.group', 'storage'));

        $this->assertSame('success', $this->toast()['tone']);
        $this->assertSame('Working', $this->toast()['title']);
    }

    /**
     * The probe object is the whole point of writing rather than listing, so it must not
     * be the thing left behind.
     */
    public function test_the_probe_object_is_removed_afterwards(): void
    {
        $bucket = $this->bucket();

        $this->test_();

        $this->assertSame([], $bucket->allFiles());
    }

    public function test_only_administrators_can_run_it(): void
    {
        $this->actingAs($this->moderator)
            ->post(route('manage.settings.storage.test'))
            ->assertForbidden();
    }

    /**
     * The two credentials are write-only, so an untouched form posts nothing for them.
     * Reading that as "no credentials" would fail every test nobody had retyped.
     */
    public function test_a_blank_secret_falls_back_to_the_stored_one(): void
    {
        BrandingSetting::setValue('archive_s3_secret', 'the-stored-secret', null, true);
        config(['filesystems.disks.dvr.secret' => 'the-stored-secret']);

        // Built before the facade is mocked, or resolving it would re-enter the mock.
        $disk = app(FilesystemManager::class)->build([
            'driver' => 'local',
            'root' => storage_path('framework/testing/archive-probe'),
        ]);

        // What the probe was actually handed, which is the point of the test.
        $seen = [];

        Storage::shouldReceive('build')->twice()->andReturnUsing(function (array $config) use (&$seen, $disk) {
            $seen[] = $config['secret'];

            return $disk;
        });

        $this->test_(['secret' => '']);
        $this->test_(['secret' => Settings::MASK_SECRET]);

        $this->assertSame(['the-stored-secret', 'the-stored-secret'], $seen);
    }

    /**
     * A bucket that lists happily and refuses a PUT is exactly what this button is for,
     * and it has to be reported as the write it is rather than as a bad password.
     */
    public function test_a_bucket_that_refuses_a_write_is_reported_as_a_write_failure(): void
    {
        Storage::shouldReceive('build')->once()->andReturn($disk = \Mockery::mock());
        $disk->shouldReceive('fileExists')->once()->andReturn(false);
        $disk->shouldReceive('write')->once()->andThrow(
            new UnableToWriteFile('Access Denied'),
        );

        $this->test_();

        $this->assertSame('danger', $this->toast()['tone']);
        $this->assertSame('Write failed', $this->toast()['title']);
    }

    public function test_a_missing_bucket_is_told_apart_from_bad_credentials(): void
    {
        Storage::shouldReceive('build')->once()->andReturn($disk = \Mockery::mock());
        $disk->shouldReceive('fileExists')->once()->andThrow(
            new \RuntimeException('The specified bucket does not exist'),
        );

        $this->test_();

        $this->assertSame('Bucket not found', $this->toast()['title']);
    }

    public function test_credentials_that_are_refused_say_so(): void
    {
        Storage::shouldReceive('build')->once()->andReturn($disk = \Mockery::mock());
        $disk->shouldReceive('fileExists')->once()->andThrow(
            new \RuntimeException('The request signature we calculated does not match'),
        );

        $this->test_();

        $this->assertSame('Credentials rejected', $this->toast()['title']);
    }

    /**
     * A test of unsaved values must not leave the process pointing at them.
     */
    public function test_it_does_not_change_the_configured_disk(): void
    {
        $this->bucket();

        config(['filesystems.disks.dvr.bucket' => 'the-real-bucket']);

        $this->test_(['bucket' => 'a-bucket-being-tried']);

        $this->assertSame('the-real-bucket', config('filesystems.disks.dvr.bucket'));
    }
}
