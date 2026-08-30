<?php

namespace Tests\Feature;

use App\Models\BannedSub;
use App\Models\ChatBan;
use App\Models\Message;
use App\Models\Timeout;
use App\Models\User;
use App\Support\AccountArchive;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

/**
 * Closing an account, and taking a copy of one.
 *
 * The delete is a hard delete, so what is worth pinning is that it really takes the
 * rest with it, and that the one thing it must not take - a standing sanction - comes
 * back when the same identity signs in again. Otherwise deleting is how a ban is
 * lifted.
 */
class AccountDeletionTest extends TestCase
{
    use RefreshDatabase;

    private function viewer(array $overrides = []): User
    {
        return User::create(array_merge([
            'name' => 'Test Viewer',
            'sub' => 'sub-'.uniqid(),
        ], $overrides));
    }

    public function test_the_account_and_what_it_wrote_are_deleted(): void
    {
        $user = $this->viewer();
        Message::create(['user_id' => $user->id, 'message' => 'hello']);

        $this->actingAs($user)
            ->delete(route('account.destroy'), ['confirmation' => 'Test Viewer'])
            // An account the provider owns leaves through the front channel, or the
            // provider signs the same person straight back in and remakes it.
            ->assertRedirect(route('auth.frontchannel-logout'));

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseMissing('messages', ['user_id' => $user->id]);
        $this->assertGuest();
    }

    public function test_a_confirmation_that_does_not_match_the_name_deletes_nothing(): void
    {
        $user = $this->viewer();

        $this->actingAs($user)
            ->delete(route('account.destroy'), ['confirmation' => 'test viewer'])
            ->assertSessionHasErrors('confirmation');

        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_a_standing_ban_survives_deleting_and_signing_in_again(): void
    {
        $user = $this->viewer(['sub' => 'identity-1']);
        ChatBan::create(['user_id' => $user->id, 'reason' => 'spam', 'expires_at' => null]);

        $this->actingAs($user)->delete(route('account.destroy'), ['confirmation' => 'Test Viewer']);

        $this->assertDatabaseHas('banned_subs', ['sub' => 'identity-1', 'kind' => BannedSub::KIND_BAN]);

        $returned = User::create(['name' => 'Test Viewer', 'sub' => 'identity-1']);

        $this->assertNotNull($returned->activeChatBan());
        $this->assertSame('spam', $returned->activeChatBan()->reason);
        // Consumed: the sanction is an ordinary row again, so lifting it lifts it.
        $this->assertDatabaseCount('banned_subs', 0);
    }

    public function test_a_timeout_that_ran_out_while_the_account_was_gone_is_not_reinstated(): void
    {
        $moderator = $this->viewer(['name' => 'Moderator']);
        $user = $this->viewer(['sub' => 'identity-2']);
        Timeout::create([
            'user_id' => $user->id,
            'issued_by_user_id' => $moderator->id,
            'reason' => 'slow down',
            'expires_at' => now()->addMinutes(5),
        ]);

        $this->actingAs($user)->delete(route('account.destroy'), ['confirmation' => 'Test Viewer']);

        $this->travel(10)->minutes();

        $returned = User::create(['name' => 'Test Viewer', 'sub' => 'identity-2']);

        $this->assertNull($returned->activeTimeout());
        $this->assertDatabaseCount('banned_subs', 0);
    }

    public function test_an_account_with_no_identity_holds_nothing(): void
    {
        $user = User::create(['name' => 'Local Viewer', 'password' => 'secret-secret']);
        ChatBan::create(['user_id' => $user->id, 'reason' => 'spam', 'expires_at' => null]);

        $this->actingAs($user)
            ->delete(route('account.destroy'), ['confirmation' => 'Local Viewer'])
            ->assertRedirect('/');

        $this->assertDatabaseCount('banned_subs', 0);
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_the_export_is_a_zip_of_the_account(): void
    {
        $user = $this->viewer();
        Message::create(['user_id' => $user->id, 'message' => 'hello']);

        $path = AccountArchive::build($user);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);

        $payload = json_decode($zip->getFromName('account.json'), true);

        $this->assertSame('Test Viewer', $payload['account']['name']);
        $this->assertSame('hello', $payload['chat_messages'][0]['message']);
        // A live credential is not a record of anything and is not in it.
        $this->assertArrayNotHasKey('streamkey', $payload['account']);

        // The same rows again as a spreadsheet, header first.
        $csv = $zip->getFromName('data/chat-messages.csv');
        $this->assertStringContainsString('message,type,sent_at,deleted_at', $csv);
        $this->assertStringContainsString('hello', $csv);

        // Nothing was written for a kind this account has none of.
        $this->assertFalse($zip->getFromName('data/comments.csv'));

        $zip->close();
        unlink($path);
    }

    public function test_the_export_downloads_as_an_attachment(): void
    {
        $user = $this->viewer();

        $response = $this->actingAs($user)->get(route('account.export'));

        $response->assertOk();
        $this->assertStringContainsString('attachment;', $response->headers->get('content-disposition'));
        $this->assertStringContainsString('.zip', $response->headers->get('content-disposition'));
    }

    public function test_the_export_needs_a_signed_in_account(): void
    {
        $this->get(route('account.export'))->assertRedirect();
    }
}
