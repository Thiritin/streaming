<?php

namespace App\Policies;

use App\Models\RecordingComment;
use App\Models\User;

/**
 * Who may take a comment down.
 *
 * Its author, always - somebody who posted a thing has to be able to unpost it -
 * and anyone who moderates the site. Reading the panel's list is the same wider
 * half as feedback: a moderator watching a stream should not have to wait for an
 * admin to look at what was posted under it.
 */
class RecordingCommentPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Only its author, and moderators deliberately not: putting words in somebody
     * else's mouth is what deleting exists instead of.
     */
    public function update(User $user, RecordingComment $comment): bool
    {
        return $comment->user_id === $user->id;
    }

    public function delete(User $user, RecordingComment $comment): bool
    {
        return $comment->user_id === $user->id || $this->moderates($user);
    }

    /**
     * The class-level check, for the bulk action that holds no single comment.
     */
    public function manage(User $user): bool
    {
        return $this->moderates($user);
    }

    private function moderates(User $user): bool
    {
        return $user->hasPermission('stream.manage')
            || $user->hasPermission('admin.access')
            || $user->canModerateChat()
            || $user->isStaff();
    }
}
