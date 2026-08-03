<?php

namespace Tests\Feature\Manage;

use App\Models\BrandingSetting;
use App\Models\PretalxRoomSource;
use App\Models\Show;
use App\Models\Source;
use App\Support\Manage\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesManageUsers;
use Tests\TestCase;

/**
 * Importing the programme from pretalx: what the screen offers, what an import creates,
 * and the rule that a slot is only ever imported once.
 */
class PretalxImportTest extends TestCase
{
    use CreatesManageUsers;
    use RefreshDatabase;

    private const EVENT = 'testcon-2026';

    private const INSTANCE = 'https://pretalx.test';

    protected Source $stage;

    protected Source $side;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createManageUsers();

        $this->stage = Source::factory()->create(['name' => 'Main Stage']);
        $this->side = Source::factory()->create(['name' => 'Side Room']);
    }

    private function configure(?string $token = null): void
    {
        BrandingSetting::setValue('pretalx_url', self::INSTANCE);
        BrandingSetting::setValue('pretalx_event', self::EVENT);

        if ($token !== null) {
            BrandingSetting::setValue('pretalx_token', $token);
        }
    }

    /**
     * The instance as the connection test sees it: the event list, plus a slot count for
     * whichever event is asked about.
     */
    private function fakeInstance(): void
    {
        Http::fake([
            // Ordered: the first matching pattern wins, and the event wildcard would
            // otherwise swallow the slots URL too.
            self::INSTANCE.'/api/events/*/slots*' => Http::response(['count' => 42, 'next' => null, 'results' => []]),
            self::INSTANCE.'/api/events/*' => Http::response([
                ['slug' => self::EVENT, 'name' => ['en' => 'Testcon 2026'], 'date_from' => '2026-08-17', 'date_to' => '2026-08-23'],
                ['slug' => 'testcon-2025', 'name' => ['en' => 'Testcon 2025'], 'date_from' => '2025-08-18', 'date_to' => '2025-08-24'],
            ]),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function toast(): array
    {
        return session(\Inertia\SessionKey::FlashData->value)['toast'] ?? [];
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $slots
     */
    private function fakePretalx(?array $slots = null): void
    {
        Http::fake([
            self::INSTANCE.'/api/events/'.self::EVENT.'/rooms/*' => Http::response([
                'next' => null,
                'results' => [
                    ['id' => 1, 'name' => ['en' => 'Main Stage'], 'position' => 0],
                    ['id' => 2, 'name' => ['en' => 'Side Room'], 'position' => 1],
                ],
            ]),
            self::INSTANCE.'/api/events/'.self::EVENT.'/slots/*' => Http::response([
                'next' => null,
                'results' => $slots ?? $this->slots(),
            ]),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function slots(): array
    {
        return [
            [
                'id' => 11,
                'room' => 1,
                'start' => '2026-08-17T10:00:00+02:00',
                'end' => '2026-08-17T11:00:00+02:00',
                'submission' => [
                    'code' => 'ABCDEF',
                    'title' => 'Opening Ceremony',
                    'abstract' => 'The one everybody watches.',
                    'speakers' => [['name' => 'Chair']],
                ],
            ],
            [
                'id' => 12,
                'room' => 2,
                'start' => '2026-08-17T12:00:00+02:00',
                'end' => '2026-08-17T13:00:00+02:00',
                'submission' => [
                    'code' => 'GHIJKL',
                    'title' => 'Panel: Streaming',
                    'abstract' => null,
                    'speakers' => [],
                ],
            ],
            // Not scheduled anywhere: nothing to put on a timeline, so it never appears.
            [
                'id' => 13,
                'room' => null,
                'start' => null,
                'end' => null,
                'submission' => ['code' => 'MNOPQR', 'title' => 'Unscheduled'],
            ],
        ];
    }

    public function test_page_reports_when_pretalx_is_not_configured(): void
    {
        $this->actingAs($this->admin)
            ->get(route('manage.shows.import'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Manage/Shows/Import')
                ->where('configured', false)
                ->where('slots', [])
            );
    }

    public function test_page_lists_scheduled_slots_with_their_rooms(): void
    {
        $this->configure();
        $this->fakePretalx();

        $this->actingAs($this->admin)
            ->get(route('manage.shows.import'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Manage/Shows/Import')
                ->where('configured', true)
                ->where('event', self::EVENT)
                ->has('rooms', 2)
                ->has('slots', 2)
                ->where('slots.0.title', 'Opening Ceremony')
                ->where('slots.0.speakers', ['Chair'])
                ->where('slots.0.showUrl', null)
                ->where('slots.1.title', 'Panel: Streaming')
            );
    }

    public function test_api_token_is_sent_when_one_is_stored(): void
    {
        $this->configure(token: 'secret-token');
        $this->fakePretalx();

        $this->actingAs($this->admin)->get(route('manage.shows.import'))->assertOk();

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Token secret-token'));
    }

    public function test_a_failing_pretalx_is_reported_rather_than_thrown(): void
    {
        $this->configure();

        Http::fake([self::INSTANCE.'/*' => Http::response(['detail' => 'nope'], 403)]);

        $this->actingAs($this->admin)
            ->get(route('manage.shows.import'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('configured', true)
                ->whereNot('error', null)
                ->where('slots', [])
            );
    }

    public function test_import_creates_shows_on_the_mapped_channels(): void
    {
        $this->configure();
        $this->fakePretalx();

        $this->actingAs($this->admin)
            ->post(route('manage.shows.import.store'), [
                'rooms' => [
                    ['id' => 1, 'name' => 'Main Stage', 'source_id' => $this->stage->id],
                    ['id' => 2, 'name' => 'Side Room', 'source_id' => $this->side->id],
                ],
                'slots' => ['11', '12'],
            ])
            ->assertRedirect();

        $this->assertSame(2, Show::count());

        $opening = Show::where('pretalx_slot_id', '11')->firstOrFail();

        $this->assertSame('Opening Ceremony', $opening->title);
        $this->assertSame('The one everybody watches.', $opening->description);
        $this->assertSame($this->stage->id, $opening->source_id);
        $this->assertSame('scheduled', $opening->status);
        $this->assertSame('2026-08-17 08:00:00', $opening->scheduled_start->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-17 09:00:00', $opening->scheduled_end->utc()->format('Y-m-d H:i:s'));

        // The mapping is remembered, so the next import does not ask again.
        $this->assertSame(
            [1 => $this->stage->id, 2 => $this->side->id],
            PretalxRoomSource::mapFor(self::EVENT),
        );
    }

    public function test_slots_in_unmapped_rooms_are_skipped(): void
    {
        $this->configure();
        $this->fakePretalx();

        $this->actingAs($this->admin)
            ->post(route('manage.shows.import.store'), [
                'rooms' => [
                    ['id' => 1, 'name' => 'Main Stage', 'source_id' => $this->stage->id],
                    ['id' => 2, 'name' => 'Side Room', 'source_id' => null],
                ],
                'slots' => ['11', '12'],
            ])
            ->assertRedirect();

        $this->assertSame(1, Show::count());
        $this->assertDatabaseMissing('shows', ['pretalx_slot_id' => '12']);
    }

    public function test_an_imported_slot_cannot_be_imported_twice(): void
    {
        $this->configure();
        $this->fakePretalx();

        $payload = [
            'rooms' => [['id' => 1, 'name' => 'Main Stage', 'source_id' => $this->stage->id]],
            'slots' => ['11'],
        ];

        $this->actingAs($this->admin)->post(route('manage.shows.import.store'), $payload);
        $this->actingAs($this->admin)->post(route('manage.shows.import.store'), $payload);

        $this->assertSame(1, Show::where('pretalx_slot_id', '11')->count());

        // And the screen says so, rather than offering it again.
        $this->actingAs($this->admin)
            ->get(route('manage.shows.import'))
            ->assertInertia(fn (Assert $page) => $page->whereNot('slots.0.showUrl', null));
    }

    public function test_deleting_the_show_releases_the_slot(): void
    {
        $this->configure();
        $this->fakePretalx();

        $payload = [
            'rooms' => [['id' => 1, 'name' => 'Main Stage', 'source_id' => $this->stage->id]],
            'slots' => ['11'],
        ];

        $this->actingAs($this->admin)->post(route('manage.shows.import.store'), $payload);

        $show = Show::where('pretalx_slot_id', '11')->firstOrFail();
        $this->actingAs($this->admin)->delete(route('manage.shows.destroy', $show))->assertRedirect();

        $this->actingAs($this->admin)->post(route('manage.shows.import.store'), $payload);

        $this->assertSame(1, Show::where('pretalx_slot_id', '11')->count());
        $this->assertNotSame($show->id, Show::where('pretalx_slot_id', '11')->value('id'));
    }

    public function test_two_sessions_with_the_same_title_and_day_get_distinct_slugs(): void
    {
        $this->configure();
        $this->fakePretalx([
            [
                'id' => 21,
                'room' => 1,
                'start' => '2026-08-17T10:00:00+02:00',
                'end' => '2026-08-17T11:00:00+02:00',
                'submission' => ['code' => 'AAA', 'title' => 'Fursuit Parade', 'speakers' => []],
            ],
            [
                'id' => 22,
                'room' => 2,
                'start' => '2026-08-17T10:00:00+02:00',
                'end' => '2026-08-17T11:00:00+02:00',
                'submission' => ['code' => 'BBB', 'title' => 'Fursuit Parade', 'speakers' => []],
            ],
        ]);

        $this->actingAs($this->admin)
            ->post(route('manage.shows.import.store'), [
                'rooms' => [
                    ['id' => 1, 'name' => 'Main Stage', 'source_id' => $this->stage->id],
                    ['id' => 2, 'name' => 'Side Room', 'source_id' => $this->side->id],
                ],
                'slots' => ['21', '22'],
            ])
            ->assertRedirect();

        $this->assertSame(2, Show::count());
        $this->assertSame(2, Show::distinct()->count('slug'));
    }

    public function test_saving_only_the_room_mapping_imports_nothing(): void
    {
        $this->configure();
        $this->fakePretalx();

        $this->actingAs($this->admin)
            ->post(route('manage.shows.import.store'), [
                'rooms' => [['id' => 1, 'name' => 'Main Stage', 'source_id' => $this->stage->id]],
                'slots' => [],
            ])
            ->assertRedirect();

        $this->assertSame(0, Show::count());
        $this->assertSame([1 => $this->stage->id], PretalxRoomSource::mapFor(self::EVENT));
    }

    public function test_paging_follows_the_next_link_instead_of_rereading_page_one(): void
    {
        // Regression: passing an empty query array to Http::get replaces the `next` link's
        // own query string, which dropped `page` and `expand` and read page one over and
        // over - hundreds of duplicate, title-less rows.
        $this->configure();

        $page = fn (int $id, ?string $next) => Http::response([
            'count' => 2,
            'next' => $next,
            'results' => [[
                'id' => $id,
                'room' => 1,
                'start' => '2026-08-17T10:00:00+02:00',
                'end' => '2026-08-17T11:00:00+02:00',
                'submission' => ['code' => 'C'.$id, 'title' => 'Session '.$id, 'speakers' => []],
            ]],
        ]);

        Http::fake([
            self::INSTANCE.'/api/events/'.self::EVENT.'/rooms/*' => Http::response([
                'next' => null,
                'results' => [['id' => 1, 'name' => ['en' => 'Main Stage'], 'position' => 0]],
            ]),
            self::INSTANCE.'/api/events/'.self::EVENT.'/slots/?*page=2*' => $page(2, null),
            self::INSTANCE.'/api/events/'.self::EVENT.'/slots/*' => $page(
                1,
                self::INSTANCE.'/api/events/'.self::EVENT.'/slots/?expand=submission&page=2&page_size=100',
            ),
        ]);

        $this->actingAs($this->admin)
            ->get(route('manage.shows.import'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('slots', 2)
                ->where('slots.0.title', 'Session 1')
                ->where('slots.1.title', 'Session 2')
            );
    }

    public function test_the_import_button_appears_only_once_pretalx_is_configured(): void
    {
        $names = fn ($page) => collect($page->toArray()['props']['table']['pageActions'])->pluck('name')->all();

        $this->actingAs($this->admin)
            ->get(route('manage.shows.index'))
            ->assertInertia(fn (Assert $page) => $this->assertNotContains('import', $names($page)));

        $this->configure();

        $this->actingAs($this->admin)
            ->get(route('manage.shows.index'))
            ->assertInertia(fn (Assert $page) => $this->assertContains('import', $names($page)));
    }

    public function test_testing_the_connection_reports_what_the_credentials_can_see(): void
    {
        $this->fakeInstance();

        $this->actingAs($this->admin)
            ->post(route('manage.settings.pretalx.test'), [
                'url' => self::INSTANCE,
                'event' => self::EVENT,
                'token' => 'typed-token',
            ])
            ->assertRedirect();

        // The event list is remembered, so the settings page can offer it as a dropdown.
        $this->actingAs($this->admin)
            ->get(route('manage.settings'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('pretalxEvents', 2)
                ->where('pretalxEvents.0.slug', self::EVENT)
            );

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Token typed-token'));
    }

    public function test_testing_the_connection_falls_back_to_the_stored_token(): void
    {
        BrandingSetting::setValue('pretalx_token', 'stored-token');
        $this->fakeInstance();

        $this->actingAs($this->admin)
            ->post(route('manage.settings.pretalx.test'), [
                'url' => self::INSTANCE,
                'event' => self::EVENT,
                // What the page posts back when the operator did not retype the secret.
                'token' => Settings::MASK_SECRET,
            ])
            ->assertRedirect();

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Token stored-token'));
    }

    public function test_testing_an_unreachable_instance_reports_the_failure(): void
    {
        Http::fake([self::INSTANCE.'/*' => Http::response(['detail' => 'nope'], 403)]);

        $this->actingAs($this->admin)
            ->post(route('manage.settings.pretalx.test'), [
                'url' => self::INSTANCE,
                'event' => self::EVENT,
            ])
            ->assertRedirect();

        $this->assertSame('danger', $this->toast()['tone'] ?? null);

        $this->actingAs($this->admin)
            ->get(route('manage.settings'))
            ->assertInertia(fn (Assert $page) => $page->where('pretalxEvents', []));
    }

    public function test_testing_an_event_the_credentials_cannot_see_is_a_warning(): void
    {
        $this->fakeInstance();

        $this->actingAs($this->admin)
            ->post(route('manage.settings.pretalx.test'), [
                'url' => self::INSTANCE,
                'event' => 'no-such-event',
            ])
            ->assertRedirect();

        $this->assertSame('warning', $this->toast()['tone'] ?? null);
    }

    public function test_only_administrators_can_test_the_connection(): void
    {
        $this->actingAs($this->moderator)
            ->post(route('manage.settings.pretalx.test'), ['url' => self::INSTANCE])
            ->assertForbidden();
    }

    public function test_the_import_screen_is_closed_to_users_who_cannot_create_shows(): void
    {
        $this->actingAs($this->viewer)->get(route('manage.shows.import'))->assertForbidden();
        $this->actingAs($this->viewer)->post(route('manage.shows.import.store'), [])->assertForbidden();
    }
}
