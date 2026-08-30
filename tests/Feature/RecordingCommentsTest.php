<?php

namespace Tests\Feature;

use App\Http\Controllers\RecordingCommentController;
use App\Models\Recording;
use App\Models\RecordingComment;
use App\Models\Role;
use App\Models\User;
use App\Support\CommentText;
use App\Support\Features;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class RecordingCommentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Features::flush();
    }

    private function recording(array $overrides = []): Recording
    {
        return Recording::create(array_merge([
            'title' => 'A show',
            'date' => now()->subDay(),
            'duration' => 3600,
            'views' => 0,
            'is_published' => true,
            'status' => 'ready',
            'm3u8_url' => 'https://example.test/a.m3u8',
        ], $overrides));
    }

    public function test_a_signed_in_viewer_can_comment_and_the_page_carries_the_thread(): void
    {
        $recording = $this->recording();
        $user = User::factory()->create(['name' => 'Tin']);

        $this->actingAs($user)
            ->post(route('recordings.comments.store', $recording), ['body' => 'Good set'])
            ->assertRedirect();

        $this->actingAs($user)
            ->get(route('recordings.show', $recording))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('commentsEnabled', true)
                ->where('canComment', true)
                ->has('comments', 1)
                ->where('comments.0.body', 'Good set')
                ->where('comments.0.author.name', 'Tin')
                ->where('comments.0.can_delete', true)
                ->has('comments.0.replies', 0)
            );
    }

    public function test_a_guest_reads_the_thread_but_is_not_offered_the_box(): void
    {
        // An installation that lets guests browse. With AUTH_REQUIRED on there is
        // no guest to read the thread in the first place.
        config()->set('auth.required', false);

        $recording = $this->recording();
        $author = User::factory()->create();

        RecordingComment::create([
            'recording_id' => $recording->id,
            'user_id' => $author->id,
            'body' => 'Said out loud',
        ]);

        $this->get(route('recordings.show', $recording))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('canComment', false)
                ->has('comments', 1)
                ->where('comments.0.can_delete', false)
            );

        $this->post(route('recordings.comments.store', $recording), ['body' => 'Me too'])
            ->assertRedirect(route('login'));

        $this->assertSame(1, RecordingComment::count());
    }

    public function test_a_reply_hangs_off_its_comment_and_a_thread_never_goes_deeper(): void
    {
        $recording = $this->recording();
        $user = User::factory()->create();

        $comment = RecordingComment::create([
            'recording_id' => $recording->id,
            'user_id' => $user->id,
            'body' => 'Top',
        ]);

        // One viewer's posts are spaced out; see RecordingCommentController::COOLDOWN_SECONDS.
        $this->travel(RecordingCommentController::COOLDOWN_SECONDS + 1)->seconds();

        $this->actingAs($user)->post(route('recordings.comments.store', $recording), [
            'body' => 'First reply',
            'parent_id' => $comment->id,
        ])->assertRedirect();

        $reply = RecordingComment::where('body', 'First reply')->firstOrFail();

        // One viewer's posts are spaced out; see RecordingCommentController::COOLDOWN_SECONDS.
        $this->travel(RecordingCommentController::COOLDOWN_SECONDS + 1)->seconds();

        // Replying to a reply is filed under the same parent rather than nesting.
        $this->actingAs($user)->post(route('recordings.comments.store', $recording), [
            'body' => 'Reply to the reply',
            'parent_id' => $reply->id,
        ])->assertRedirect();

        $this->assertSame(
            $comment->id,
            RecordingComment::where('body', 'Reply to the reply')->firstOrFail()->parent_id,
        );

        $this->actingAs($user)
            ->get(route('recordings.show', $recording))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('comments', 1)
                ->has('comments.0.replies', 2)
            );
    }

    public function test_deleting_a_comment_takes_its_replies(): void
    {
        $recording = $this->recording();
        $author = User::factory()->create();

        $comment = RecordingComment::create([
            'recording_id' => $recording->id,
            'user_id' => $author->id,
            'body' => 'Top',
        ]);
        RecordingComment::create([
            'recording_id' => $recording->id,
            'user_id' => $author->id,
            'parent_id' => $comment->id,
            'body' => 'Under it',
        ]);

        $this->actingAs($author)
            ->delete(route('recordings.comments.destroy', [$recording, $comment]))
            ->assertRedirect();

        $this->assertSame(0, RecordingComment::count());
    }

    public function test_one_viewer_cannot_delete_another_viewers_comment(): void
    {
        $recording = $this->recording();
        $author = User::factory()->create();
        $someoneElse = User::factory()->create();

        $comment = RecordingComment::create([
            'recording_id' => $recording->id,
            'user_id' => $author->id,
            'body' => 'Mine',
        ]);

        $this->actingAs($someoneElse)
            ->delete(route('recordings.comments.destroy', [$recording, $comment]))
            ->assertForbidden();

        $this->assertSame(1, RecordingComment::count());
    }

    public function test_a_chat_ban_silences_the_comment_box_too(): void
    {
        $recording = $this->recording();
        $user = User::factory()->create();

        $user->chatBans()->create(['reason' => 'spam']);

        $this->actingAs($user)
            ->post(route('recordings.comments.store', $recording), ['body' => 'Still here'])
            ->assertSessionHasErrors('body');

        $this->assertSame(0, RecordingComment::count());
    }

    public function test_a_body_of_nothing_but_invisible_characters_is_refused(): void
    {
        $recording = $this->recording();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('recordings.comments.store', $recording), ['body' => "\u{200B}\u{200B}"])
            ->assertSessionHasErrors('body');

        $this->assertSame(0, RecordingComment::count());
    }

    public function test_a_body_past_the_cap_is_refused_rather_than_cut(): void
    {
        $recording = $this->recording();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('recordings.comments.store', $recording), [
                'body' => str_repeat('a', CommentText::MAX_LENGTH + 1),
            ])
            ->assertSessionHasErrors('body');

        $this->assertSame(0, RecordingComment::count());
    }

    public function test_the_switch_closes_the_endpoint_and_empties_the_section(): void
    {
        config()->set('features.comments', false);
        Features::flush();

        $recording = $this->recording();
        $user = User::factory()->create();

        RecordingComment::create([
            'recording_id' => $recording->id,
            'user_id' => $user->id,
            'body' => 'Posted while it was on',
        ]);

        $this->actingAs($user)
            ->post(route('recordings.comments.store', $recording), ['body' => 'Nope'])
            ->assertNotFound();

        // Nothing is deleted: switching the feature back on brings the thread back.
        $this->assertSame(1, RecordingComment::count());

        $this->actingAs($user)
            ->get(route('recordings.show', $recording))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('commentsEnabled', false)
                ->has('comments', 0)
            );
    }

    public function test_a_viewer_cannot_switch_comments_off_for_themselves(): void
    {
        $recording = $this->recording();
        $user = User::factory()->create(['feature_preferences' => ['comments' => false]]);

        RecordingComment::create([
            'recording_id' => $recording->id,
            'user_id' => $user->id,
            'body' => 'Good set',
        ]);

        $this->assertNotContains('comments', Features::switchableKeys());

        $this->actingAs($user)
            ->get(route('recordings.show', $recording))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('commentsEnabled', true)
                ->has('comments', 1)
            );
    }

    public function test_a_heart_toggles_and_the_most_hearted_leads_the_thread(): void
    {
        $recording = $this->recording();
        $author = User::factory()->create();
        $viewer = User::factory()->create();

        $quiet = RecordingComment::create([
            'recording_id' => $recording->id,
            'user_id' => $author->id,
            'body' => 'Posted first, hearted by nobody',
        ]);
        $popular = RecordingComment::create([
            'recording_id' => $recording->id,
            'user_id' => $author->id,
            'body' => 'Posted second, hearted',
        ]);

        $this->actingAs($viewer)
            ->post(route('recordings.comments.heart', [$recording, $popular]))
            ->assertRedirect();

        $this->assertSame(1, $popular->hearts()->count());

        $this->actingAs($viewer)
            ->get(route('recordings.show', $recording))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('comments.0.body', 'Posted second, hearted')
                ->where('comments.0.hearts', 1)
                ->where('comments.0.hearted', true)
                ->where('comments.1.body', 'Posted first, hearted by nobody')
                ->where('comments.1.hearted', false)
            );

        // The same endpoint takes it back rather than adding a second.
        $this->actingAs($viewer)->post(route('recordings.comments.heart', [$recording, $popular]));

        $this->assertSame(0, $popular->hearts()->count());
    }

    public function test_the_thread_is_paged_by_root_comment_and_load_more_widens_it(): void
    {
        $recording = $this->recording();
        $author = User::factory()->create();

        foreach (range(1, 25) as $index) {
            $comment = RecordingComment::create([
                'recording_id' => $recording->id,
                'user_id' => $author->id,
                'body' => "Comment {$index}",
            ]);

            // Replies ride on their parent, so they must not eat into the page.
            RecordingComment::create([
                'recording_id' => $recording->id,
                'user_id' => $author->id,
                'parent_id' => $comment->id,
                'body' => "Reply to {$index}",
            ]);
        }

        $this->actingAs($author)
            ->get(route('recordings.show', $recording))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('comments', 20)
                ->has('comments.0.replies', 1)
                ->where('commentsMeta.total', 25)
                ->where('commentsMeta.shown', 20)
                ->where('commentsMeta.hasMore', true)
            );

        $this->actingAs($author)
            ->get(route('recordings.show', ['recording' => $recording, 'comments' => 40]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('comments', 25)
                ->where('commentsMeta.hasMore', false)
            );
    }

    public function test_a_guest_cannot_heart(): void
    {
        config()->set('auth.required', false);

        $recording = $this->recording();
        $comment = RecordingComment::create([
            'recording_id' => $recording->id,
            'user_id' => User::factory()->create()->id,
            'body' => 'Worth a heart',
        ]);

        $this->post(route('recordings.comments.heart', [$recording, $comment]))
            ->assertRedirect(route('login'));

        $this->assertSame(0, $comment->hearts()->count());
    }

    /**
     * Someone who can moderate: the panel's own check is `stream.manage`, and the
     * admin role carries it.
     */
    private function moderator(): User
    {
        $moderator = User::factory()->create();
        $role = Role::create([
            'name' => 'Moderator',
            'slug' => 'comment-moderator',
            'permissions' => ['stream.manage'],
        ]);
        $moderator->roles()->attach($role);

        return $moderator;
    }

    public function test_a_report_hides_the_comment_from_the_room_but_not_from_its_author(): void
    {
        $recording = $this->recording();
        $author = User::factory()->create();
        $reporter = User::factory()->create();
        $bystander = User::factory()->create();

        $comment = RecordingComment::create([
            'recording_id' => $recording->id,
            'user_id' => $author->id,
            'body' => 'The reported one',
        ]);

        $this->actingAs($reporter)
            ->post(route('recordings.comments.report', [$recording, $comment]), [
                'message' => 'Same copypasta as the other four',
            ])
            ->assertRedirect();

        $this->assertNotNull($comment->fresh()->hidden_at);

        // Gone for everybody else.
        $this->actingAs($bystander)
            ->get(route('recordings.show', $recording))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('comments', 0)
                ->where('commentsMeta.total', 0)
            );

        // Still there for the person who wrote it, and told why.
        $this->actingAs($author)
            ->get(route('recordings.show', $recording))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('comments', 1)
                ->where('comments.0.hidden', true)
                ->where('comments.0.hidden_for', 'author')
                ->where('comments.0.reports', [])
            );
    }

    public function test_a_moderator_sees_the_report_and_can_approve_it_back(): void
    {
        $recording = $this->recording();
        $author = User::factory()->create();
        $reporter = User::factory()->create(['name' => 'Reporter']);
        $moderator = $this->moderator();

        $comment = RecordingComment::create([
            'recording_id' => $recording->id,
            'user_id' => $author->id,
            'body' => 'Contested',
        ]);

        $this->actingAs($reporter)->post(route('recordings.comments.report', [$recording, $comment]), [
            'message' => 'I just do not like it',
        ]);

        $this->actingAs($moderator)
            ->get(route('recordings.show', $recording))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('comments', 1)
                ->where('comments.0.hidden_for', 'moderator')
                ->where('comments.0.report_count', 1)
                ->where('comments.0.can_approve', true)
                ->where('comments.0.reports.0.message', 'I just do not like it')
                ->where('comments.0.reports.0.by', 'Reporter')
            );

        $this->actingAs($moderator)
            ->post(route('recordings.comments.approve', [$recording, $comment]))
            ->assertRedirect();

        $comment->refresh();
        $this->assertNull($comment->hidden_at);
        $this->assertNotNull($comment->approved_at);
        $this->assertSame(0, $comment->reports()->unresolved()->count());
    }

    public function test_an_approved_comment_cannot_be_hidden_by_reporting_it_again(): void
    {
        $recording = $this->recording();
        $comment = RecordingComment::create([
            'recording_id' => $recording->id,
            'user_id' => User::factory()->create()->id,
            'body' => 'Ruled on already',
        ]);

        $comment->approve($this->moderator());

        $this->actingAs(User::factory()->create())
            ->post(route('recordings.comments.report', [$recording, $comment]), ['message' => 'Still hate it'])
            ->assertRedirect();

        // The report is kept - an account that reports everything is only visible
        // in what it leaves behind - but the comment stays up.
        $this->assertNull($comment->fresh()->hidden_at);
        $this->assertSame(1, $comment->reports()->count());
    }

    public function test_reporting_twice_is_one_report_and_an_author_cannot_report_their_own(): void
    {
        $recording = $this->recording();
        $author = User::factory()->create();
        $reporter = User::factory()->create();

        $comment = RecordingComment::create([
            'recording_id' => $recording->id,
            'user_id' => $author->id,
            'body' => 'Mine',
        ]);

        $this->actingAs($author)
            ->post(route('recordings.comments.report', [$recording, $comment]), ['message' => 'Take it down'])
            ->assertSessionHasErrors('message');

        $this->assertNull($comment->fresh()->hidden_at);

        $this->actingAs($reporter)->post(route('recordings.comments.report', [$recording, $comment]), ['message' => 'One']);
        $this->actingAs($reporter)->post(route('recordings.comments.report', [$recording, $comment]), ['message' => 'Two']);

        $this->assertSame(1, $comment->reports()->count());
        $this->assertSame('Two', $comment->reports()->first()->message);
    }

    public function test_a_hidden_reply_leaves_its_parent_alone(): void
    {
        $recording = $this->recording();
        $author = User::factory()->create();
        $reporter = User::factory()->create();

        $parent = RecordingComment::create([
            'recording_id' => $recording->id,
            'user_id' => $author->id,
            'body' => 'Fine',
        ]);
        $reply = RecordingComment::create([
            'recording_id' => $recording->id,
            'user_id' => $author->id,
            'parent_id' => $parent->id,
            'body' => 'Not fine',
        ]);

        $this->actingAs($reporter)->post(route('recordings.comments.report', [$recording, $reply]), [
            'message' => 'Abusive',
        ]);

        $this->actingAs($reporter)
            ->get(route('recordings.show', $recording))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('comments', 1)
                ->has('comments.0.replies', 0)
            );
    }

    public function test_only_a_moderator_can_approve(): void
    {
        $recording = $this->recording();
        $comment = RecordingComment::create([
            'recording_id' => $recording->id,
            'user_id' => User::factory()->create()->id,
            'body' => 'Hidden',
        ]);
        $comment->hideOnReport();

        $this->actingAs(User::factory()->create())
            ->post(route('recordings.comments.approve', [$recording, $comment]))
            ->assertForbidden();

        $this->assertNotNull($comment->fresh()->hidden_at);
    }

    public function test_an_author_can_edit_their_own_and_nobody_else_can(): void
    {
        $recording = $this->recording();
        $author = User::factory()->create();

        $comment = RecordingComment::create([
            'recording_id' => $recording->id,
            'user_id' => $author->id,
            'body' => 'Frist',
        ]);

        $this->actingAs($author)
            ->patch(route('recordings.comments.update', [$recording, $comment]), ['body' => 'First'])
            ->assertRedirect();

        $comment->refresh();
        $this->assertSame('First', $comment->body);
        $this->assertNotNull($comment->edited_at);

        // A moderator deletes rather than rewrites: putting words in somebody
        // else's mouth is what delete exists instead of.
        $this->actingAs($this->moderator())
            ->patch(route('recordings.comments.update', [$recording, $comment]), ['body' => 'Something else'])
            ->assertForbidden();

        $this->assertSame('First', $comment->fresh()->body);
    }

    public function test_editing_drops_the_approval_so_it_can_be_reported_again(): void
    {
        $recording = $this->recording();
        $author = User::factory()->create();

        $comment = RecordingComment::create([
            'recording_id' => $recording->id,
            'user_id' => $author->id,
            'body' => 'Harmless',
        ]);
        $comment->approve($this->moderator());

        $this->actingAs($author)->patch(route('recordings.comments.update', [$recording, $comment]), [
            'body' => 'BUY CHEAP FURSUITS',
        ]);

        $this->assertNull($comment->fresh()->approved_at);

        $this->actingAs(User::factory()->create())->post(
            route('recordings.comments.report', [$recording, $comment]),
            ['message' => 'Rewritten into spam'],
        );

        $this->assertNotNull($comment->fresh()->hidden_at);
    }

    public function test_a_second_comment_has_to_wait_out_the_cooldown(): void
    {
        $recording = $this->recording();
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('recordings.comments.store', $recording), ['body' => 'One']);

        $this->actingAs($user)
            ->post(route('recordings.comments.store', $recording), ['body' => 'Two, straight after'])
            ->assertSessionHasErrors('body');

        $this->assertSame(1, RecordingComment::count());

        $this->travel(RecordingCommentController::COOLDOWN_SECONDS + 1)->seconds();

        $this->actingAs($user)
            ->post(route('recordings.comments.store', $recording), ['body' => 'Two, a moment later'])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, RecordingComment::count());
    }

    public function test_a_moderator_bans_the_author_from_the_report_and_the_box_closes(): void
    {
        $recording = $this->recording();
        $author = User::factory()->create();
        $admin = User::factory()->create();
        $adminRole = Role::create([
            'name' => 'Admin',
            'slug' => 'admin',
            'permissions' => ['stream.manage', 'chat.ban', 'access-manage'],
        ]);
        $admin->roles()->attach($adminRole);

        $comment = RecordingComment::create([
            'recording_id' => $recording->id,
            'user_id' => $author->id,
            'body' => 'The one that did it',
        ]);

        $this->actingAs($admin)
            ->post(route('manage.comments.ban', $comment), ['duration' => '7d', 'reason' => 'Spam'])
            ->assertRedirect();

        $this->assertNotNull($author->fresh()->activeChatBan());

        $this->actingAs($author)
            ->post(route('recordings.comments.store', $recording), ['body' => 'Back again'])
            ->assertSessionHasErrors('body');

        // What they already posted stays up until somebody deletes it.
        $this->assertSame(1, RecordingComment::count());
    }

    public function test_the_switch_closes_the_panel_module_too(): void
    {
        // The panel needs the gate as well as the permission; see AuthServiceProvider.
        $moderator = User::factory()->create();
        $moderator->roles()->attach(Role::create([
            'name' => 'Panel admin',
            'slug' => 'panel-admin',
            'permissions' => ['stream.manage', 'admin.access'],
        ]));

        $comment = RecordingComment::create([
            'recording_id' => $this->recording()->id,
            'user_id' => User::factory()->create()->id,
            'body' => 'Still in the table',
        ]);

        $this->actingAs($moderator)->get(route('manage.comments.index'))->assertOk();

        config()->set('features.comments', false);
        Features::flush();

        // Every route in the module, not only the list: a link somebody kept must
        // not be a way back into a feature the installation has switched off.
        $this->actingAs($moderator)->get(route('manage.comments.index'))->assertNotFound();
        $this->actingAs($moderator)->get(route('manage.comments.show', $comment))->assertNotFound();
        $this->actingAs($moderator)->post(route('manage.comments.approve', $comment))->assertNotFound();
        $this->actingAs($moderator)->delete(route('manage.comments.destroy', $comment))->assertNotFound();

        $this->assertSame(1, RecordingComment::count());
    }

    public function test_a_banned_viewer_gets_no_comment_section_at_all(): void
    {
        $recording = $this->recording();
        $banned = User::factory()->create();

        RecordingComment::create([
            'recording_id' => $recording->id,
            'user_id' => $banned->id,
            'body' => 'Written before the ban',
        ]);
        RecordingComment::create([
            'recording_id' => $recording->id,
            'user_id' => User::factory()->create()->id,
            'body' => 'Somebody else',
        ]);

        $banned->chatBans()->create(['reason' => 'spam']);

        $this->actingAs($banned)
            ->get(route('recordings.show', $recording))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                // Not a read-only section: being handed the transcript of a
                // conversation you have been thrown out of is worse than nothing.
                ->where('commentsEnabled', false)
                ->has('comments', 0)
            );

        $comment = RecordingComment::where('body', 'Somebody else')->firstOrFail();

        $this->actingAs($banned)
            ->post(route('recordings.comments.heart', [$recording, $comment]))
            ->assertForbidden();

        $this->actingAs($banned)
            ->post(route('recordings.comments.report', [$recording, $comment]), ['message' => 'Spite'])
            ->assertForbidden();

        // Everyone else still has it.
        $this->actingAs(User::factory()->create())
            ->get(route('recordings.show', $recording))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('commentsEnabled', true)
                ->has('comments', 2)
            );
    }
}
