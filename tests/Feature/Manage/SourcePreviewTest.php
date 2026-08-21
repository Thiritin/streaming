<?php

namespace Tests\Feature\Manage;

use App\Enum\ServerStatusEnum;
use App\Enum\ServerTypeEnum;
use App\Models\Server;
use App\Models\Show;
use App\Models\Source;
use App\Models\SourceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesManageUsers;
use Tests\TestCase;

/**
 * The preview page, and the `preview=1` playlist mode it plays through.
 */
class SourcePreviewTest extends TestCase
{
    use CreatesManageUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config(['stream.token.viewer_secret' => str_repeat('a', 64)]);

        $this->createManageUsers();
    }

    private function edge(): Server
    {
        return Server::create([
            'hostname' => 'edge.example.com',
            'port' => 8080,
            'type' => ServerTypeEnum::EDGE,
            'status' => ServerStatusEnum::ACTIVE,
            'viewer_count' => 0,
            'max_clients' => 100,
            'hetzner_id' => 'preview-edge',
            'ip' => '10.0.0.1',
        ]);
    }

    private function fakeEdge(): void
    {
        Http::fake([
            '*' => Http::response("#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=3500000\ntest-stream_hd.m3u8\n", 200),
        ]);
    }

    // ---------------------------------------------------------------- access

    public function test_guests_do_not_see_the_page_at_all(): void
    {
        $this->get(route('manage.sources.preview'))->assertNotFound();
    }

    public function test_a_user_without_the_gate_is_forbidden(): void
    {
        $this->actingAs($this->viewer)->get(route('manage.sources.preview'))->assertForbidden();
    }

    // ---------------------------------------------------------------- the page

    public function test_it_lists_every_source_and_selects_the_first_by_default(): void
    {
        $first = Source::factory()->create(['slug' => 'main', 'priority' => 10]);
        Source::factory()->create(['slug' => 'second', 'priority' => 1]);

        $this->actingAs($this->admin)
            ->get(route('manage.sources.preview'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Manage/Sources/Preview')
                ->has('sources', 2)
                ->where('selected.slug', $first->slug)
                ->where('selected.hls_url', fn (string $url) => str_contains($url, 'preview=1')));
    }

    public function test_it_selects_the_requested_source(): void
    {
        Source::factory()->create(['slug' => 'main', 'priority' => 10]);
        Source::factory()->create(['slug' => 'second', 'priority' => 1]);

        $this->actingAs($this->admin)
            ->get(route('manage.sources.preview', ['source' => 'second']))
            ->assertInertia(fn (Assert $page) => $page->where('selected.slug', 'second'));
    }

    public function test_it_renders_without_a_source(): void
    {
        $this->actingAs($this->admin)
            ->get(route('manage.sources.preview'))
            ->assertInertia(fn (Assert $page) => $page->has('sources', 0)->where('selected', null));
    }

    // ---------------------------------------------------------------- preview playback

    public function test_a_preview_plays_a_source_with_no_show_on_it(): void
    {
        $source = Source::factory()->create(['slug' => 'test-stream']);
        $this->edge();
        $this->fakeEdge();

        // The show gate is the whole reason this page exists: nothing is scheduled
        // yet and the operator still needs to see the feed.
        $this->actingAs($this->admin)->get('/hls/test-stream/master.m3u8')->assertNotFound();

        $this->actingAs($this->admin)
            ->get('/hls/test-stream/master.m3u8?preview=1')
            ->assertOk()
            // The flag rides on into the variant requests the player makes next.
            ->assertSee('/hls/test-stream_hd.m3u8?preview=1', false);

        $this->assertSame(0, SourceUser::where('source_id', $source->id)->count());
    }

    public function test_watching_normally_still_opens_a_viewer_session(): void
    {
        $source = Source::factory()->create(['slug' => 'test-stream']);
        Show::factory()->create(['source_id' => $source->id, 'status' => 'live']);
        $this->edge();
        $this->fakeEdge();

        $this->actingAs($this->admin)->get('/hls/test-stream/master.m3u8')->assertOk();

        $this->assertSame(1, SourceUser::where('source_id', $source->id)->count());
    }

    public function test_the_flag_is_ignored_for_someone_without_the_gate(): void
    {
        $source = Source::factory()->create(['slug' => 'test-stream']);
        Show::factory()->create(['source_id' => $source->id, 'status' => 'live']);
        $this->edge();
        $this->fakeEdge();

        $this->actingAs($this->viewer)->get('/hls/test-stream/master.m3u8?preview=1')->assertOk();

        $this->assertSame(1, SourceUser::where('source_id', $source->id)->count());
    }
}
