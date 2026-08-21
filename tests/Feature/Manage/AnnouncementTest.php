<?php

namespace Tests\Feature\Manage;

use App\Models\BrandingSetting;
use App\Support\Announcement;
use App\Support\Features;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesManageUsers;
use Tests\TestCase;

class AnnouncementTest extends TestCase
{
    use CreatesManageUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createManageUsers();
    }

    /**
     * Save the announcement pane, which is the only one this posts: a pane carries
     * only its own fields.
     */
    private function save(array $overrides = []): TestResponse
    {
        return $this->actingAs($this->admin)
            ->from(route('manage.settings.group', 'announcement'))
            ->put(route('manage.settings.update', 'announcement'), ['values' => array_merge([
                'announcement_enabled' => true,
                'announcement_level' => 'info',
                'announcement_title' => '',
                'announcement_body' => '',
                'announcement_details' => '',
                'announcement_link_url' => '',
                'announcement_link_label' => '',
                'announcement_dismissible' => true,
            ], $overrides)]);
    }

    public function test_a_fresh_installation_has_no_announcement(): void
    {
        $this->assertNull(Announcement::current());
    }

    public function test_saving_one_from_the_panel_puts_it_on_the_page(): void
    {
        $this->save([
            'announcement_title' => 'Main Stage is running late',
            'announcement_body' => 'Doors open at **20:00**.',
            'announcement_level' => 'warning',
        ])->assertRedirect(route('manage.settings.group', 'announcement'));

        $announcement = Announcement::current();

        $this->assertSame('Main Stage is running late', $announcement['title']);
        $this->assertStringContainsString('<strong>20:00</strong>', $announcement['html']);
        $this->assertSame('warning', $announcement['level']);
        $this->assertTrue($announcement['dismissible']);
    }

    public function test_the_switch_takes_it_down_without_losing_the_text(): void
    {
        BrandingSetting::setValue('announcement_enabled', '1');
        BrandingSetting::setValue('announcement_body', 'Something to say.');

        $this->assertNotNull(Announcement::current());

        BrandingSetting::setValue('announcement_enabled', '0');

        $this->assertNull(Announcement::current());
        $this->assertSame('Something to say.', BrandingSetting::getValue('announcement_body'));
    }

    public function test_an_empty_body_means_no_banner_whatever_the_switch_says(): void
    {
        BrandingSetting::setValue('announcement_enabled', '1');
        BrandingSetting::setValue('announcement_body', '   ');

        $this->assertNull(Announcement::current());
    }

    public function test_editing_the_text_changes_the_id_so_a_dismissal_stops_applying(): void
    {
        BrandingSetting::setValue('announcement_enabled', '1');
        BrandingSetting::setValue('announcement_body', 'First version.');

        $first = Announcement::current()['id'];

        BrandingSetting::setValue('announcement_body', 'Second version.');

        $this->assertNotSame($first, Announcement::current()['id']);
    }

    public function test_the_level_has_to_be_one_of_the_known_ones(): void
    {
        $this->save([
            'announcement_body' => 'Something to say.',
            'announcement_level' => 'apocalyptic',
        ])->assertSessionHasErrors('values.announcement_level');
    }

    public function test_raw_html_in_the_body_is_stripped_rather_than_rendered(): void
    {
        BrandingSetting::setValue('announcement_enabled', '1');
        BrandingSetting::setValue('announcement_body', 'Careful <script>alert(1)</script> now.');

        $this->assertStringNotContainsString('<script>', Announcement::current()['html']);
    }

    public function test_a_read_more_link_is_carried_with_the_banner(): void
    {
        BrandingSetting::setValue('announcement_enabled', '1');
        BrandingSetting::setValue('announcement_body', 'Doors open at 20:00.');
        BrandingSetting::setValue('announcement_link_url', 'https://example.test/news');
        BrandingSetting::setValue('announcement_link_label', 'What changed');

        $this->assertSame(
            ['url' => 'https://example.test/news', 'label' => 'What changed'],
            Announcement::current()['link'],
        );
    }

    public function test_a_link_with_no_label_reads_read_more(): void
    {
        BrandingSetting::setValue('announcement_enabled', '1');
        BrandingSetting::setValue('announcement_body', 'Doors open at 20:00.');
        BrandingSetting::setValue('announcement_link_url', '/schedule');

        $this->assertSame('Read more', Announcement::current()['link']['label']);
    }

    public function test_no_link_means_the_banner_is_the_whole_message(): void
    {
        BrandingSetting::setValue('announcement_enabled', '1');
        BrandingSetting::setValue('announcement_body', 'Doors open at 20:00.');

        $this->assertNull(Announcement::current()['link']);
    }

    public function test_the_link_has_to_be_an_address_or_a_path_on_this_site(): void
    {
        $this->save([
            'announcement_body' => 'Something to say.',
            'announcement_link_url' => 'javascript:alert(1)',
        ])->assertSessionHasErrors('values.announcement_link_url');

        $this->save([
            'announcement_body' => 'Something to say.',
            'announcement_link_url' => '/schedule',
        ])->assertSessionHasNoErrors();
    }

    public function test_a_full_announcement_gives_the_banner_a_page_to_link_to(): void
    {
        BrandingSetting::setValue('announcement_enabled', '1');
        BrandingSetting::setValue('announcement_body', 'Doors open at 20:00.');
        BrandingSetting::setValue('announcement_details', "The **whole** story.\n\nSecond paragraph.");

        $this->assertSame('/announcement', Announcement::current()['link']['url']);
        $this->assertSame('Read more', Announcement::current()['link']['label']);
    }

    public function test_the_page_carries_the_banner_line_and_the_full_text(): void
    {
        BrandingSetting::setValue('announcement_enabled', '1');
        BrandingSetting::setValue('announcement_title', 'Main Stage is running late');
        BrandingSetting::setValue('announcement_body', 'Doors open at 20:00.');
        BrandingSetting::setValue('announcement_details', 'The **whole** story.');

        $this->actingAs($this->admin)
            ->get(route('announcement'))
            ->assertSuccessful()
            ->assertInertia(fn ($page) => $page
                ->component('Announcement')
                ->where('announcementPage.title', 'Main Stage is running late')
                ->where('announcementPage.level', 'info')
                ->where('announcementPage.summaryHtml', fn ($html) => str_contains($html, 'Doors open at 20:00.'))
                ->where('announcementPage.html', fn ($html) => str_contains($html, '<strong>whole</strong>')));
    }

    public function test_the_page_is_not_there_without_a_full_announcement(): void
    {
        BrandingSetting::setValue('announcement_enabled', '1');
        BrandingSetting::setValue('announcement_body', 'Doors open at 20:00.');

        $this->actingAs($this->admin)->get(route('announcement'))->assertNotFound();
    }

    public function test_the_page_goes_when_the_banner_is_switched_off(): void
    {
        BrandingSetting::setValue('announcement_enabled', '0');
        BrandingSetting::setValue('announcement_body', 'Doors open at 20:00.');
        BrandingSetting::setValue('announcement_details', 'The whole story.');

        $this->actingAs($this->admin)->get(route('announcement'))->assertNotFound();
    }

    public function test_an_explicit_link_wins_over_the_announcement_page(): void
    {
        BrandingSetting::setValue('announcement_enabled', '1');
        BrandingSetting::setValue('announcement_body', 'Doors open at 20:00.');
        BrandingSetting::setValue('announcement_details', 'The whole story.');
        BrandingSetting::setValue('announcement_link_url', 'https://example.test/news');

        $this->assertSame('https://example.test/news', Announcement::current()['link']['url']);
    }

    public function test_the_feature_switch_takes_the_whole_thing_away(): void
    {
        BrandingSetting::setValue('announcement_enabled', '1');
        BrandingSetting::setValue('announcement_body', 'Doors open at 20:00.');
        BrandingSetting::setValue('announcement_details', 'The whole story.');

        $this->assertNotNull(Announcement::current());

        BrandingSetting::setValue('announcement', '0');

        $this->assertNull(Announcement::current());
        $this->actingAs($this->admin)->get(route('announcement'))->assertNotFound();
    }

    public function test_a_viewer_cannot_switch_announcements_off_for_themselves(): void
    {
        $this->assertNotContains('announcement', Features::switchableKeys());
    }

    public function test_the_banner_reaches_the_front_page(): void
    {
        BrandingSetting::setValue('announcement_enabled', '1');
        BrandingSetting::setValue('announcement_body', 'Doors open at 20:00.');

        $this->actingAs($this->admin)
            ->get(route('shows.grid'))
            ->assertSuccessful()
            ->assertInertia(fn ($page) => $page
                ->component('ShowsGrid')
                ->where('announcement.level', 'info'));
    }

    public function test_the_banner_is_not_carried_onto_other_pages(): void
    {
        BrandingSetting::setValue('announcement_enabled', '1');
        BrandingSetting::setValue('announcement_body', 'Doors open at 20:00.');

        $this->actingAs($this->admin)
            ->get(route('schedule.index'))
            ->assertSuccessful()
            ->assertInertia(fn ($page) => $page->missing('announcement'));
    }
}
