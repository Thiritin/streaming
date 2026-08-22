<?php

namespace Tests\Feature\Manage;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\SessionKey;
use Tests\Concerns\CreatesManageUsers;
use Tests\TestCase;

class UploadTest extends TestCase
{
    use CreatesManageUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createManageUsers();
    }

    public function test_a_show_thumbnail_lands_on_the_private_disk_in_the_expected_directory(): void
    {
        Storage::fake('s3');

        $this->actingAs($this->admin)
            ->from('/manage')
            ->post(route('manage.uploads.store'), [
                'purpose' => 'show_thumbnail',
                'file' => UploadedFile::fake()->image('Opening Ceremony.png', 640, 360),
            ])
            ->assertRedirect('/manage')
            ->assertSessionHas(SessionKey::FlashData->value.'.upload.path', 'shows/thumbnails/opening-ceremony.png');

        Storage::disk('s3')->assertExists('shows/thumbnails/opening-ceremony.png');
    }

    public function test_a_recording_thumbnail_gets_a_random_name(): void
    {
        Storage::fake('s3');

        $response = $this->actingAs($this->admin)
            ->from('/manage')
            ->post(route('manage.uploads.store'), [
                'purpose' => 'recording_thumbnail',
                'file' => UploadedFile::fake()->image('frame.jpg', 1280, 720),
            ]);

        $path = session(SessionKey::FlashData->value.'.upload.path');

        $response->assertRedirect('/manage');
        $this->assertStringStartsWith('recordings/thumbnails/', $path);
        $this->assertStringEndsWith('.jpg', $path);
        Storage::disk('s3')->assertExists($path);
    }

    /**
     * Branding used to land on the local `public` disk, which cannot work here: app pods
     * are replicas with their own ephemeral filesystems, so a logo uploaded through one
     * of them was invisible to the other nine and gone at the next deploy. It took the
     * branding logo with it on 18 Aug 2026 - `/storage/branding/...` served a 503.
     */
    public function test_branding_uploads_land_on_the_bucket(): void
    {
        Storage::fake('s3');

        $this->actingAs($this->admin)
            ->from('/manage')
            ->post(route('manage.uploads.store'), [
                'purpose' => 'branding_logo',
                'file' => UploadedFile::fake()->image('logo.png'),
            ])
            ->assertSessionHas(SessionKey::FlashData->value.'.upload.path', 'branding/logo.png');

        Storage::disk('s3')->assertExists('branding/logo.png');
    }

    public function test_a_favicon_lands_beside_the_logo_on_the_bucket(): void
    {
        Storage::fake('s3');

        $this->actingAs($this->admin)
            ->from('/manage')
            ->post(route('manage.uploads.store'), [
                'purpose' => 'branding_favicon',
                'file' => UploadedFile::fake()->image('Tab Icon.png', 64, 64),
            ])
            ->assertSessionHas(SessionKey::FlashData->value.'.upload.path', 'branding/tab-icon.png');

        Storage::disk('s3')->assertExists('branding/tab-icon.png');
    }

    /**
     * Public visibility is the point: the logo is on every page including the login
     * screen, so it has to be a plain cacheable URL. A signed one would expire and 403.
     */
    public function test_branding_is_the_one_purpose_stored_publicly(): void
    {
        foreach (config('manage.uploads') as $purpose => $config) {
            $this->assertSame(
                's3',
                $config['disk'],
                "Upload purpose '{$purpose}' must use the bucket; the local disk is per-pod.",
            );

            $this->assertSame(
                str_starts_with($purpose, 'branding_') ? 'public' : 'private',
                $config['visibility'],
                "Upload purpose '{$purpose}' has the wrong visibility.",
            );
        }
    }

    public function test_the_toast_survives_the_redirect_as_a_top_level_flash_prop(): void
    {
        Storage::fake('s3');

        $this->actingAs($this->admin)
            ->from('/manage')
            ->post(route('manage.uploads.store'), [
                'purpose' => 'show_thumbnail',
                'file' => UploadedFile::fake()->image('thumb.png'),
            ]);

        // `flash` is a top-level key on the page object rather than a prop, which is what
        // keeps it out of the browser's history state: a back navigation cannot replay an
        // old toast. Hence the assertion reads the page directly instead of AssertableInertia.
        $flash = $this->actingAs($this->admin)
            ->get(route('manage.servers.index'))
            ->assertSuccessful()
            ->viewData('page')['flash'];

        $this->assertSame([
            'purpose' => 'show_thumbnail',
            'path' => 'shows/thumbnails/thumb.png',
        ], collect($flash['upload'])->only(['purpose', 'path'])->all());

        $this->assertSame([
            'tone' => 'success',
            'title' => 'File uploaded',
            'body' => 'thumb.png',
        ], $flash['toast']);
    }

    public function test_flash_data_is_not_repeated_on_the_next_request(): void
    {
        Storage::fake('s3');

        $this->actingAs($this->admin)
            ->from('/manage')
            ->post(route('manage.uploads.store'), [
                'purpose' => 'show_thumbnail',
                'file' => UploadedFile::fake()->image('thumb.png'),
            ]);

        $this->actingAs($this->admin)->get(route('manage.servers.index'));

        $page = $this->actingAs($this->admin)->get(route('manage.servers.index'))->viewData('page');

        $this->assertArrayNotHasKey('flash', $page);
    }

    public function test_an_unknown_purpose_is_rejected(): void
    {
        Storage::fake('s3');

        $this->actingAs($this->admin)
            ->from('/manage')
            ->post(route('manage.uploads.store'), [
                'purpose' => 'anything_goes',
                'file' => UploadedFile::fake()->image('x.png'),
            ])
            ->assertSessionHasErrors('purpose');
    }

    public function test_a_mime_type_the_purpose_does_not_allow_is_rejected(): void
    {
        Storage::fake('s3');

        $this->actingAs($this->admin)
            ->from('/manage')
            ->post(route('manage.uploads.store'), [
                'purpose' => 'show_thumbnail',
                'file' => UploadedFile::fake()->create('payload.pdf', 10, 'application/pdf'),
            ])
            ->assertSessionHasErrors('file');

        $this->assertEmpty(Storage::disk('s3')->allFiles());
    }

    public function test_a_file_over_the_purpose_limit_is_rejected(): void
    {
        Storage::fake('s3');

        $this->actingAs($this->admin)
            ->from('/manage')
            ->post(route('manage.uploads.store'), [
                'purpose' => 'show_thumbnail',
                'file' => UploadedFile::fake()->create('huge.png', 6000, 'image/png'),
            ])
            ->assertSessionHasErrors('file');
    }

    public function test_uploads_require_the_manage_gate(): void
    {
        Storage::fake('s3');

        $this->actingAs($this->viewer)
            ->post(route('manage.uploads.store'), [
                'purpose' => 'show_thumbnail',
                'file' => UploadedFile::fake()->image('x.png'),
            ])
            ->assertForbidden();
    }
}
