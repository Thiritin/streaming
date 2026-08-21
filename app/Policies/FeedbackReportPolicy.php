<?php

namespace App\Policies;

use App\Models\FeedbackReport;
use App\Models\User;

/**
 * Same shape as EmbedKeyPolicy: reading is open to anyone past the `access-manage`
 * gate, triaging and deleting needs `stream.manage`.
 *
 * Reading is deliberately the wider half. A moderator watching a stream is usually
 * the first to see "audio is out on Main Stage" arrive, and making them wait for an
 * admin to read it defeats the point of collecting it.
 */
class FeedbackReportPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, FeedbackReport $report): bool
    {
        return true;
    }

    public function update(User $user, FeedbackReport $report): bool
    {
        return $this->manages($user);
    }

    public function delete(User $user, FeedbackReport $report): bool
    {
        return $this->manages($user);
    }

    /**
     * The class-level check, for the bulk actions that hold no single report.
     */
    public function manage(User $user): bool
    {
        return $this->manages($user);
    }

    private function manages(User $user): bool
    {
        return $user->hasPermission('stream.manage') || $user->hasPermission('admin.access') || $user->isStaff();
    }
}
