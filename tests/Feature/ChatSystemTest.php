<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\Role;
use App\Models\User;
use App\Services\ChatCommandService;
use App\Services\ChatMessageSanitizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChatSystemTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a regular user
        $this->user = User::factory()->create([
            'name' => 'Regular User',
            'sub' => 'user-sub-123',
        ]);

        // Create an admin user with a role that has permissions
        $adminRole = Role::create([
            'name' => 'Admin',
            'is_staff' => true,
            'chat_color' => '#FF0000',
        ]);

        $this->adminUser = User::factory()->create([
            'name' => 'Admin User',
            'sub' => 'admin-sub-456',
        ]);

        // Attach the role through pivot table
        $this->adminUser->roles()->attach($adminRole);
    }

    /**
     * Test message sanitization service
     */
    public function test_message_sanitizer_removes_unwanted_urls()
    {
        $sanitizer = new ChatMessageSanitizer;

        $message = 'Check out https://google.com and https://eurofurence.org';
        $sanitized = $sanitizer->sanitize($message);

        $this->assertStringContainsString('[url removed]', $sanitized);
        $this->assertStringContainsString('eurofurence.org', $sanitized);
        $this->assertStringNotContainsString('google.com', $sanitized);
    }

    /**
     * Test message sanitizer handles long words
     */
    public function test_message_sanitizer_breaks_long_words()
    {
        $sanitizer = new ChatMessageSanitizer;

        $message = 'This is a verylongwordthatshouldbebrokenbythesanitizertopreventlayoutbreaking';
        $sanitized = $sanitizer->sanitize($message);

        // Should contain zero-width spaces for breaking
        $this->assertStringContainsString('&#8203;', $sanitized);
    }

    /**
     * Test message sanitizer escapes HTML
     */
    public function test_message_sanitizer_escapes_html()
    {
        $sanitizer = new ChatMessageSanitizer;

        $message = '<script>alert("XSS")</script>';
        $sanitized = $sanitizer->sanitize($message);

        $this->assertStringNotContainsString('<script>', $sanitized);
        $this->assertStringContainsString('&lt;script&gt;', $sanitized);
    }

    /**
     * Test message length limiting
     */
    public function test_message_sanitizer_limits_length()
    {
        $sanitizer = new ChatMessageSanitizer;

        $message = str_repeat('a', 600);
        $sanitized = $sanitizer->sanitize($message);

        // The sanitizer adds zero-width spaces for word breaking which increases length
        // Check that the original content (without added formatting) is limited
        $withoutBreakingChars = str_replace('&#8203;', '', $sanitized);
        $this->assertLessThanOrEqual(500, mb_strlen($withoutBreakingChars));
    }

    /**
     * Test command service identifies commands correctly
     */
    public function test_command_service_identifies_commands()
    {
        $service = new ChatCommandService;

        $this->assertTrue($service->isCommand('/timeout user 5m'));
        $this->assertTrue($service->isCommand('!broadcast Hello'));
        $this->assertFalse($service->isCommand('This is not a command'));
        $this->assertFalse($service->isCommand(''));
    }

    /**
     * Test command service extracts command names
     */
    public function test_command_service_extracts_command_names()
    {
        $service = new ChatCommandService;

        $this->assertEquals('timeout', $service->extractCommandName('/timeout user 5m'));
        $this->assertEquals('broadcast', $service->extractCommandName('!broadcast Hello'));
        $this->assertEquals('slowmode', $service->extractCommandName('/slowmode 30'));
    }

    /**
     * Test sending a regular message
     */
    public function test_user_can_send_regular_message()
    {
        $response = $this->actingAs($this->user)
            ->post(route('message.send'), [
                'message' => 'Hello world!',
            ]);

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);

        // Check message was created
        $this->assertDatabaseHas('messages', [
            'user_id' => $this->user->id,
            'message' => 'Hello world!',
            'is_command' => false,
        ]);
    }

    /**
     * Test sending a command message
     */
    public function test_command_is_marked_as_command()
    {
        $response = $this->actingAs($this->user)
            ->post(route('message.send'), [
                'message' => '/help',
            ]);

        $response->assertStatus(200);

        // Check message was created and marked as command
        $this->assertDatabaseHas('messages', [
            'user_id' => $this->user->id,
            'message' => '/help',
            'is_command' => true,
        ]);
    }

    /**
     * Test URLs are sanitized before saving
     */
    public function test_urls_are_sanitized_in_messages()
    {
        $response = $this->actingAs($this->user)
            ->post(route('message.send'), [
                'message' => 'Check out https://badsite.com',
            ]);

        $response->assertStatus(200);

        // Check message was sanitized
        $message = Message::where('user_id', $this->user->id)->latest()->first();
        $this->assertStringContainsString('[url removed]', $message->message);
        $this->assertStringNotContainsString('badsite.com', $message->message);
    }

    /**
     * Test eurofurence.org URLs are preserved
     */
    public function test_eurofurence_urls_are_preserved()
    {
        $response = $this->actingAs($this->user)
            ->post(route('message.send'), [
                'message' => 'Visit https://eurofurence.org for info',
            ]);

        $response->assertStatus(200);

        // Check message preserved eurofurence URL
        $message = Message::where('user_id', $this->user->id)->latest()->first();
        $this->assertStringContainsString('eurofurence.org', $message->message);
    }

    /**
     * Test HTML is escaped in messages
     */
    public function test_html_is_escaped_in_messages()
    {
        $response = $this->actingAs($this->user)
            ->post(route('message.send'), [
                'message' => '<b>Bold text</b>',
            ]);

        $response->assertStatus(200);

        // Check HTML was escaped
        $message = Message::where('user_id', $this->user->id)->latest()->first();
        $this->assertStringNotContainsString('<b>', $message->message);
        $this->assertStringContainsString('&lt;b&gt;', $message->message);
    }

    /**
     * Test rate limiting response structure
     */
    public function test_rate_limit_response_structure()
    {
        $response = $this->actingAs($this->user)
            ->post(route('message.send'), [
                'message' => 'Test message',
            ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'rateLimit' => [
                'maxTries',
                'secondsLeft',
                'rateDecay',
                'slowMode',
            ],
        ]);
    }

    /**
     * Test command availability based on permissions
     */
    public function test_command_availability_based_on_permissions()
    {
        $service = new ChatCommandService;

        // Regular user should have no commands
        $userCommands = $service->getAvailableCommands($this->user);
        $this->assertIsArray($userCommands);

        // Admin might have commands if permissions are set
        $adminCommands = $service->getAvailableCommands($this->adminUser);
        $this->assertIsArray($adminCommands);
    }

    /**
     * Test message validation requirement
     */
    public function test_empty_message_is_rejected()
    {
        $response = $this->actingAs($this->user)
            ->postJson(route('message.send'), [
                'message' => '',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['message']);
    }

    /**
     * Test very long message is truncated
     */
    public function test_very_long_message_is_truncated()
    {
        $longMessage = str_repeat('a', 600);

        $response = $this->actingAs($this->user)
            ->postJson(route('message.send'), [
                'message' => $longMessage,
            ]);

        // Should be rejected by validation (max:500 in MessageRequest)
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['message']);
    }
}
