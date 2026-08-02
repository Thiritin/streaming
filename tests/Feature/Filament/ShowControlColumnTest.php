<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ShowResource\Pages\ListShows;
use App\Models\Role;
use App\Models\Show;
use App\Models\Source;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ShowControlColumnTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = Role::create([
            'name' => 'Administrator',
            'slug' => 'admin',
            'description' => 'Admin role for testing',
            'permissions' => ['admin.access', 'filament.access'],
            'priority' => 100,
        ]);

        $this->adminUser = User::factory()->create();
        $this->adminUser->roles()->attach($adminRole);
    }

    /** @test */
    public function the_control_column_renders_go_live_and_end_stream()
    {
        $source = Source::factory()->create();
        $scheduled = Show::factory()->create(['source_id' => $source->id, 'status' => 'scheduled']);
        $live = Show::factory()->create(['source_id' => $source->id, 'status' => 'live']);

        Livewire::actingAs($this->adminUser)
            ->test(ListShows::class)
            ->assertCanSeeTableRecords([$scheduled, $live])
            ->assertTableColumnStateSet('live_control', 'Go Live', $scheduled)
            ->assertTableColumnStateSet('live_control', 'End Stream', $live);
    }

    /** @test */
    public function the_control_column_takes_a_scheduled_show_live()
    {
        $show = Show::factory()->create([
            'source_id' => Source::factory()->create()->id,
            'status' => 'scheduled',
        ]);

        Livewire::actingAs($this->adminUser)
            ->test(ListShows::class)
            ->mountTableAction('toggle_live', $show)
            ->callMountedTableAction();

        $this->assertSame('live', $show->fresh()->status);
    }

    /** @test */
    public function the_control_column_ends_a_live_show()
    {
        $show = Show::factory()->create([
            'source_id' => Source::factory()->create()->id,
            'status' => 'live',
        ]);

        Livewire::actingAs($this->adminUser)
            ->test(ListShows::class)
            ->mountTableAction('toggle_live', $show)
            ->callMountedTableAction();

        $this->assertSame('ended', $show->fresh()->status);
    }
}
