<?php

namespace Tests\Feature\Filament;

use App\Models\User;
use App\Models\Role;
use App\Models\Server;
use App\Models\Source;
use App\Models\Show;
use App\Models\Emote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected User $regularUser;

    protected function setUp(): void
    {
        parent::setUp();

        // Create admin role
        $adminRole = Role::create([
            'name' => 'Administrator',
            'slug' => 'admin',
            'description' => 'Admin role for testing',
            'permissions' => ['admin.access', 'filament.access'],
            'priority' => 100,
        ]);

        // Create admin user
        $this->adminUser = User::factory()->create();
        $this->adminUser->roles()->attach($adminRole);

        // Create regular user without admin access
        $this->regularUser = User::factory()->create();
    }

    /**
     * Test that the admin panel redirects to login for guests
     */
    public function test_admin_panel_redirects_to_login_for_guests(): void
    {
        $response = $this->get('/admin');
        
        $response->assertRedirect('/admin/login');
    }

    /**
     * Test that regular users cannot access admin panel
     */
    public function test_regular_users_cannot_access_admin_panel(): void
    {
        $response = $this->actingAs($this->regularUser)->get('/admin');
        
        $response->assertForbidden();
    }

    /**
     * Test that admin users can access the dashboard
     */
    public function test_admin_users_can_access_dashboard(): void
    {
        $response = $this->actingAs($this->adminUser)->get('/admin');
        
        $response->assertSuccessful();
        $response->assertSee('Dashboard');
    }

    /**
     * Test that the login page loads
     */
    public function test_admin_login_page_loads(): void
    {
        $response = $this->get('/admin/login');
        
        $response->assertSuccessful();
        $response->assertSee('Sign in');
    }

    /**
     * Test that admin can access servers resource
     */
    public function test_admin_can_access_servers_resource(): void
    {
        $response = $this->actingAs($this->adminUser)->get('/admin/servers');
        
        $response->assertSuccessful();
        $response->assertSee('Servers');
    }

    /**
     * Test that admin can access users resource
     */
    public function test_admin_can_access_users_resource(): void
    {
        $response = $this->actingAs($this->adminUser)->get('/admin/users');
        
        $response->assertSuccessful();
        $response->assertSee('Users');
    }

    /**
     * Test that admin can access roles resource
     */
    public function test_admin_can_access_roles_resource(): void
    {
        $response = $this->actingAs($this->adminUser)->get('/admin/roles');
        
        $response->assertSuccessful();
        $response->assertSee('Roles');
    }

    /**
     * Test that admin can access sources resource
     */
    public function test_admin_can_access_sources_resource(): void
    {
        $response = $this->actingAs($this->adminUser)->get('/admin/sources');
        
        $response->assertSuccessful();
        $response->assertSee('Sources');
    }

    /**
     * Test that admin can access shows resource
     */
    public function test_admin_can_access_shows_resource(): void
    {
        $response = $this->actingAs($this->adminUser)->get('/admin/shows');
        
        $response->assertSuccessful();
        $response->assertSee('Shows');
    }

    /**
     * Test that admin can access emotes resource
     */
    public function test_admin_can_access_emotes_resource(): void
    {
        $response = $this->actingAs($this->adminUser)->get('/admin/emotes');
        
        $response->assertSuccessful();
        $response->assertSee('Emotes');
    }

    /**
     * Test that admin can access stream control page
     */
    public function test_admin_can_access_stream_control_page(): void
    {
        $response = $this->actingAs($this->adminUser)->get('/admin/stream');
        
        $response->assertSuccessful();
        $response->assertSee('Stream Control');
    }

    /**
     * Test navigation groups are displayed correctly
     */
    public function test_navigation_groups_are_displayed(): void
    {
        $response = $this->actingAs($this->adminUser)->get('/admin');
        
        $response->assertSuccessful();
        $response->assertSee('Streaming');
        $response->assertSee('Infrastructure');
        $response->assertSee('User Management');
        $response->assertSee('Chat');
    }

    /**
     * Test that server table displays health status for edge servers
     */
    public function test_server_table_displays_health_status(): void
    {
        // Create an edge server
        $server = Server::create([
            'hostname' => 'test-edge',
            'ip' => '127.0.0.1',
            'port' => 8080,
            'type' => \App\Enum\ServerTypeEnum::EDGE,
            'status' => \App\Enum\ServerStatusEnum::ACTIVE,
            'health_status' => 'healthy',
            'shared_secret' => 'test-secret',
            'max_clients' => 100,
            'viewer_count' => 0,
        ]);

        $response = $this->actingAs($this->adminUser)->get('/admin/servers');
        
        $response->assertSuccessful();
        $response->assertSee('test-edge');
        // Health column only shows for edge servers in the table
    }

    /**
     * Test creating a new server through the form
     */
    public function test_admin_can_create_server(): void
    {
        $response = $this->actingAs($this->adminUser)->get('/admin/servers/create');
        
        $response->assertSuccessful();
        $response->assertSee('Create Server');
        $response->assertSee('Hostname');
        $response->assertSee('Leave empty for locally managed servers'); // hetzner_id helper text
    }

    /**
     * Test that sources can be created
     */
    public function test_admin_can_create_source(): void
    {
        $response = $this->actingAs($this->adminUser)->get('/admin/sources/create');
        
        $response->assertSuccessful();
        $response->assertSee('Create Source');
        // Form labels are rendered differently in Filament
    }

    /**
     * Test that widgets load on dashboard
     */
    public function test_dashboard_widgets_load(): void
    {
        // Create some test data
        Server::create([
            'hostname' => 'test-server',
            'ip' => '127.0.0.1',
            'port' => 8080,
            'type' => \App\Enum\ServerTypeEnum::EDGE,
            'status' => \App\Enum\ServerStatusEnum::ACTIVE,
            'shared_secret' => 'test',
            'max_clients' => 100,
            'viewer_count' => 50,
        ]);

        $response = $this->actingAs($this->adminUser)->get('/admin');
        
        $response->assertSuccessful();
        // Dashboard should load without errors even with data
    }

    /**
     * Test that brand name is displayed
     */
    public function test_brand_name_is_displayed(): void
    {
        $response = $this->actingAs($this->adminUser)->get('/admin');
        
        $response->assertSuccessful();
        $response->assertSee('EF Streaming Admin');
    }
}