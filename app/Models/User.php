<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $guarded = [];

    protected $appends = ['role', 'chat_color'];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        // Only the features this viewer has switched off, keyed as in
        // config/features.php. See App\Support\Features::forUser().
        'feature_preferences' => 'array',
    ];

    /**
     * A permanent personal credential for playback outside a browser.
     *
     * It used to be issued and revoked by the edge-assignment code, which meant losing
     * an edge silently invalidated the URL a viewer had already pasted into VLC. The
     * key identifies the viewer, not the edge that happens to be serving them, so it
     * is issued once and kept.
     */
    public function ensureStreamkey(): string
    {
        if (! $this->streamkey) {
            $this->forceFill(['streamkey' => Str::random(32)])->save();
        }

        return $this->streamkey;
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * Shows the user is watching/has watched.
     */
    public function shows()
    {
        return $this->belongsToMany(Show::class, 'show_user')
            ->withPivot('joined_at', 'left_at', 'watch_duration')
            ->withTimestamps();
    }

    /**
     * Get the current show the user is watching.
     */
    public function currentShow()
    {
        return $this->shows()
            ->whereNull('show_user.left_at')
            ->where('shows.status', 'live')
            ->first();
    }

    /**
     * Join a show.
     */
    public function joinShow(Show $show)
    {
        // Leave any current show first
        $currentShow = $this->currentShow();
        if ($currentShow) {
            $this->leaveShow($currentShow);
        }

        // Join the new show
        $this->shows()->attach($show->id, [
            'joined_at' => now(),
        ]);

        // Update show viewer count
        $show->updateViewerCount();
    }

    /**
     * Leave a show.
     */
    public function leaveShow(Show $show)
    {
        $pivot = $this->shows()
            ->where('show_id', $show->id)
            ->whereNull('show_user.left_at')
            ->first();

        if ($pivot) {
            $joinedAt = $pivot->pivot->joined_at;
            $duration = now()->diffInSeconds($joinedAt);

            $this->shows()->updateExistingPivot($show->id, [
                'left_at' => now(),
                'watch_duration' => $duration,
            ]);

            // Update show viewer count
            $show->updateViewerCount();
        }
    }

    /**
     * Get the roles for this user.
     */
    public function roles()
    {
        return $this->belongsToMany(Role::class, 'role_user')
            ->withPivot('assigned_by_user_id')
            ->withTimestamps();
    }

    /**
     * Get active roles.
     */
    public function activeRoles()
    {
        return $this->roles();
    }

    /**
     * Check if user has a specific role.
     */
    public function hasRole(string $roleSlug): bool
    {
        return $this->activeRoles()->where('slug', $roleSlug)->exists();
    }

    /**
     * Check if user has any of the given roles.
     */
    public function hasAnyRole(array $roles): bool
    {
        return $this->activeRoles()->whereIn('slug', $roles)->exists();
    }

    /**
     * Check if user has all of the given roles.
     */
    public function hasAllRoles(array $roles): bool
    {
        return $this->activeRoles()->whereIn('slug', $roles)->count() === count($roles);
    }

    /**
     * Assign a role to the user.
     */
    public function assignRole($role, ?User $assignedBy = null): void
    {
        if (is_string($role)) {
            $role = Role::where('slug', $role)->first();
        }

        if ($role) {
            $role->assignTo($this, $assignedBy);
        }
    }

    /**
     * Remove a role from the user.
     */
    public function removeRole($role): void
    {
        if (is_string($role)) {
            $role = Role::where('slug', $role)->first();
        }

        if ($role) {
            $role->removeFrom($this);
        }
    }

    /**
     * Rewrite the roles the identity provider owns from what it just told us.
     *
     * Ownership is decided by the role, not by this call: a role carrying an
     * `external_id` is the provider's to give and take away, and a role without
     * one is never touched here, however it was assigned.
     *
     * @param  array<int, string>  $externalIds  Group IDs and package names from the provider.
     */
    public function syncRolesFromLogin(array $externalIds): void
    {
        \Log::info('Before sync - User '.$this->id.' roles: ', $this->roles()->pluck('slug')->toArray());
        \Log::info('Syncing roles from login: ', $externalIds);

        // Drop every provider-owned role first, so one that is no longer granted
        // actually goes away rather than lingering from a previous login.
        $roleIdsToDetach = $this->roles()
            ->loginAssigned()
            ->pluck('roles.id')
            ->toArray();

        if (! empty($roleIdsToDetach)) {
            $this->roles()->detach($roleIdsToDetach);
        }

        $roles = Role::loginAssigned()
            ->whereIn('external_id', $externalIds)
            ->get();

        \Log::info('Adding roles: ', $roles->pluck('slug')->toArray());

        foreach ($roles as $role) {
            $role->assignTo($this, null);
        }

        \Log::info('After sync - User '.$this->id.' roles: ', $this->roles()->pluck('slug')->toArray());
    }

    /**
     * An account this installation holds itself, rather than one the identity
     * provider owns. Local accounts have no subject; that is the whole difference.
     */
    public function isLocal(): bool
    {
        return $this->sub === null;
    }

    /**
     * Give this account the baseline role, if the installation declares one.
     *
     * An OIDC sign-in gets it from the mapping in OidcClientController, which appends
     * Role::BASELINE_EXTERNAL_ID to every successful sign-in. A local account has no
     * provider to hand it one, so it is attached as the account is created and the two
     * kinds end up the same citizen. Nothing else is granted automatically: any further
     * role is an administrator's decision, in /manage > Users.
     */
    public function assignBaselineRole(): void
    {
        // Not before the address is confirmed: the baseline is what makes an account
        // an attendee, and self-registration is open to anybody with a mail client.
        if (! $this->hasVerifiedEmail()) {
            return;
        }

        Role::query()
            ->where('external_id', Role::BASELINE_EXTERNAL_ID)
            ->first()
            ?->assignTo($this, null);
    }

    /**
     * Check if user has permission.
     */
    public function hasPermission(string $permission): bool
    {
        foreach ($this->activeRoles as $role) {
            if ($role->hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if user is staff (admin only).
     */
    public function isStaff(): bool
    {
        return $this->hasRole('admin');
    }

    /**
     * Get the highest priority role for display.
     */
    public function getRoleAttribute(): ?Role
    {
        return $this->activeRoles()
            ->visible()
            ->ordered()
            ->first();
    }

    /**
     * Get the chat color from the highest priority role.
     */
    public function getChatColorAttribute(): string
    {
        $role = $this->role;

        return $role ? $role->chat_color : '#808080';
    }

    /**
     * Check if user is admin.
     */
    public function isAdmin(): bool
    {
        return $this->hasRole('admin') || $this->hasPermission('admin.access');
    }

    /**
     * Check if user is moderator.
     */
    public function isModerator(): bool
    {
        return $this->hasRole('moderator') || $this->hasPermission('chat.moderate');
    }

    /**
     * Get uploaded emotes.
     */
    public function uploadedEmotes()
    {
        return $this->hasMany(Emote::class, 'uploaded_by_user_id');
    }

    /**
     * Get approved emotes.
     */
    public function approvedEmotes()
    {
        return $this->hasMany(Emote::class, 'approved_by_user_id');
    }

    /**
     * Get favorite emotes.
     */
    public function favoriteEmotes()
    {
        return $this->belongsToMany(Emote::class, 'user_emote_favorites')
            ->withTimestamps();
    }

    public function timeouts()
    {
        return $this->hasMany(Timeout::class);
    }

    public function chatBans()
    {
        return $this->hasMany(ChatBan::class);
    }

    /**
     * The timeout currently silencing this user, if any.
     */
    public function activeTimeout(): ?Timeout
    {
        return $this->timeouts()->active()->latest('expires_at')->first();
    }

    /**
     * Silenced: banned from chat, or timed out.
     *
     * One answer for both rooms. The comment section is not shown to somebody who
     * cannot post in it - a box that takes what is typed and refuses it is worse
     * than no box - so this is what hides the whole thing rather than only the
     * form.
     */
    public function isSilenced(): bool
    {
        return $this->activeChatBan() !== null || $this->activeTimeout() !== null;
    }

    /**
     * The ban currently silencing this user, if any.
     */
    public function activeChatBan(): ?ChatBan
    {
        return $this->chatBans()->active()->latest('id')->first();
    }

    /**
     * Can this user delete messages and time other users out?
     */
    public function canModerateChat(): bool
    {
        return $this->isAdmin() || $this->isModerator() || $this->hasPermission('chat.moderate');
    }

    /**
     * Can this user ban other users from chat?
     */
    public function canBanFromChat(): bool
    {
        return $this->isAdmin() || $this->hasPermission('chat.ban');
    }

    /**
     * Roles rendered as chat badges, highest priority first.
     */
    public function chatBadges(): array
    {
        return $this->activeRoles()
            ->visible()
            ->ordered()
            ->get()
            ->map(fn (Role $role) => [
                'slug' => $role->slug,
                'name' => $role->name,
                'label' => $role->metadata['badge'] ?? static::badgeLabelFor($role),
                'color' => $role->chat_color,
            ])
            ->values()
            ->all();
    }

    protected static function badgeLabelFor(Role $role): string
    {
        return match ($role->slug) {
            'admin' => 'ADM',
            'moderator' => 'MOD',
            'staff' => 'STF',
            'sponsor' => 'SPO',
            'supersponsor' => 'SSP',
            'attendee' => 'ATT',
            default => mb_strtoupper(mb_substr($role->name, 0, 3)),
        };
    }
}
