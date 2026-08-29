<?php

namespace Tests\Feature\Api;

use App\Models\BrandingSetting;
use App\Support\RuntimeConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The recording API's key used to be read from a config path that did not exist, so it
 * fell through to a raw env() and answered 401 to everything under config:cache. It is
 * app.recording_api_key now, saved at /manage > Settings > Playback security.
 */
class RecordingApiKeyTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_configured_key_opens_the_recording_api(): void
    {
        config(['app.recording_api_key' => 'a-recording-api-key']);

        $this->withHeader('X-Recording-Api-Key', 'a-recording-api-key')
            ->getJson(route('api.recording.shows'))
            ->assertSuccessful();
    }

    public function test_a_wrong_or_missing_key_is_refused(): void
    {
        config(['app.recording_api_key' => 'a-recording-api-key']);

        $this->withHeader('X-Recording-Api-Key', 'not-the-key')
            ->getJson(route('api.recording.shows'))
            ->assertUnauthorized();

        $this->getJson(route('api.recording.shows'))->assertUnauthorized();
    }

    public function test_nothing_configured_closes_the_recording_api(): void
    {
        config(['app.recording_api_key' => null]);

        $this->withHeader('X-Recording-Api-Key', 'anything')
            ->getJson(route('api.recording.shows'))
            ->assertUnauthorized();
    }

    public function test_a_saved_key_reaches_the_middleware(): void
    {
        BrandingSetting::setValue('recording_api_key', 'a-saved-recording-key', null, true);

        RuntimeConfig::apply();

        $this->withHeader('X-Recording-Api-Key', 'a-saved-recording-key')
            ->getJson(route('api.recording.shows'))
            ->assertSuccessful();
    }
}
