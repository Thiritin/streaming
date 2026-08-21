<?php

namespace Tests\Feature\Manage;

use App\Models\BrandingSetting;
use App\Support\Features;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesManageUsers;
use Tests\TestCase;

class ModuleSwitchTest extends TestCase
{
    use CreatesManageUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createManageUsers();
    }

    public function test_feedback_is_on_by_default(): void
    {
        $this->assertTrue(Features::feedback());
    }

    public function test_switching_it_off_closes_the_endpoint_and_the_module(): void
    {
        BrandingSetting::setValue('feedback', '0');

        $this->assertFalse(Features::feedback());

        $this->actingAs($this->admin)
            ->post(route('feedback.store'), ['message' => 'Something is broken.'])
            ->assertNotFound();

        $this->actingAs($this->admin)->get(route('manage.feedback.index'))->assertNotFound();
    }

    public function test_screens_are_on_by_default(): void
    {
        $this->assertTrue(Features::screens());
    }

    public function test_switching_screens_off_closes_the_display_routes_and_the_modules(): void
    {
        BrandingSetting::setValue('screens', '0');

        $this->assertFalse(Features::screens());

        $this->get(route('display.prompt'))->assertNotFound();
        $this->get(route('display.hub'))->assertNotFound();

        $this->actingAs($this->admin)->get(route('manage.displays.index'))->assertNotFound();
        $this->actingAs($this->admin)->get(route('manage.embed-keys.index'))->assertNotFound();
    }

    public function test_a_viewer_cannot_switch_these_off_for_themselves(): void
    {
        $switchable = Features::switchableKeys();

        $this->assertNotContains('feedback', $switchable);
        $this->assertNotContains('screens', $switchable);
        $this->assertNotContains('announcement', $switchable);
    }
}
