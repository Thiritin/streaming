<?php

namespace App\Policies;

use App\Models\Category;
use App\Models\User;

/**
 * Categories are programme furniture: anyone in the panel can see them, changing
 * the set is a streaming-management job like editing the shows they describe.
 */
class CategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Category $category): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $this->manages($user);
    }

    public function update(User $user, Category $category): bool
    {
        return $this->manages($user);
    }

    public function delete(User $user, Category $category): bool
    {
        return $this->manages($user);
    }

    private function manages(User $user): bool
    {
        return $user->hasPermission('stream.manage') || $user->hasPermission('admin.access');
    }
}
