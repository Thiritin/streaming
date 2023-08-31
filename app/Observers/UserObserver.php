<?php

namespace App\Observers;

use App\Events\ServerAssignmentChanged;
use App\Models\User;

class UserObserver
{
    public function created(User $user): void
    {

    }

    public function updated(User $user): void
    {
        if($user->isDirty('server_id')) {
            ServerAssignmentChanged::dispatch($user, is_null($user->server_id));
        }
    }

    public function deleted(User $user): void
    {
    }

    public function restored(User $user): void
    {
    }

    public function forceDeleted(User $user): void
    {
    }
}
