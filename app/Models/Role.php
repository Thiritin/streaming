<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Role extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'chat_color',
        'priority',
        'assigned_at_login',
        'is_visible',
        'permissions',
        'metadata',
    ];

    protected $casts = [
        'assigned_at_login' => 'boolean',
        'is_visible' => 'boolean',
        'permissions' => 'array',
        'metadata' => 'array',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($role) {
            if (empty($role->slug)) {
                $role->slug = Str::slug($role->name);
            }
        });

        static::updating(function ($role) {
            if ($role->isDirty('name') && !$role->isDirty('slug')) {
                $role->slug = Str::slug($role->name);
            }
        });
    }

    /**
     * Get the users that have this role.
     */
    public function users()
    {
        return $this->belongsToMany(User::class, 'role_user')
            ->withPivot('assigned_by_user_id')
            ->withTimestamps();
    }

    /**
     * Get active users.
     */
    public function activeUsers()
    {
        return $this->users();
    }

    /**
     * Check if role has a specific permission.
     */
    public function hasPermission(string $permission): bool
    {
        if (!$this->permissions) {
            return false;
        }

        return in_array($permission, $this->permissions);
    }

    /**
     * Add a permission to the role.
     */
    public function grantPermission(string $permission): void
    {
        $permissions = $this->permissions ?? [];
        
        if (!in_array($permission, $permissions)) {
            $permissions[] = $permission;
            $this->update(['permissions' => $permissions]);
        }
    }

    /**
     * Remove a permission from the role.
     */
    public function revokePermission(string $permission): void
    {
        $permissions = $this->permissions ?? [];
        $permissions = array_diff($permissions, [$permission]);
        $this->update(['permissions' => array_values($permissions)]);
    }

    /**
     * Assign this role to a user.
     */
    public function assignTo(User $user, ?User $assignedBy = null): void
    {
        $this->users()->syncWithoutDetaching([
            $user->id => [
                'assigned_by_user_id' => $assignedBy?->id,
            ]
        ]);
    }

    /**
     * Remove this role from a user.
     */
    public function removeFrom(User $user): void
    {
        $this->users()->detach($user->id);
    }

    /**
     * Scope for staff roles.
     */
    public function scopeStaff($query)
    {
        return $query->where('slug', 'admin');
    }

    /**
     * Scope for visible roles.
     */
    public function scopeVisible($query)
    {
        return $query->where('is_visible', true);
    }

    /**
     * Scope for roles assigned at login.
     */
    public function scopeLoginAssigned($query)
    {
        return $query->where('assigned_at_login', true);
    }

    /**
     * Scope for manually assigned roles.
     */
    public function scopeManuallyAssigned($query)
    {
        return $query->where('assigned_at_login', false);
    }

    /**
     * Get roles ordered by priority.
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('priority', 'desc')->orderBy('name');
    }

    /**
     * Get the chat color with fallback.
     */
    public function getChatColorAttribute($value)
    {
        return $value ?? '#808080'; // Default gray if no color set
    }

    /**
     * Check if this is an admin role.
     */
    public function isAdmin(): bool
    {
        return $this->slug === 'admin' || $this->hasPermission('admin.access');
    }

    /**
     * Check if this is a moderator role.
     */
    public function isModerator(): bool
    {
        return $this->slug === 'moderator' || $this->hasPermission('chat.moderate');
    }
}