<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enum\ServerStatusEnum;
use App\Enum\ServerTypeEnum;
use Filament\Models\Contracts\FilamentUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements FilamentUser
{
    use HasApiTokens, HasFactory, Notifiable;

    public mixed $provisioning;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $guarded = [];

    protected $appends = ['role', 'chat_color', 'badge'];

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
        'is_provisioning' => 'boolean',
        'timeout_expires_at' => 'datetime',
        'badge_type' => 'string',
    ];

    public function server()
    {
        return $this->belongsTo(Server::class);
    }

    public function clients(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Client::class);
    }

    public function getOrAssignServer()
    {
        $server = $this->server;

        if (is_null($server) || is_null($this->streamkey)) {
            if ($this->assignServerToUser()) {
                return $this->fresh()->server;
            }

            return null;
        }

        return $server;
    }

    public function getUserStreamUrls(): array
    {
        $server = $this->getOrAssignServer();
        if (is_null($server)) {
            return [
                'hls_urls' => null,
                'client_id' => null,
            ];
        }

        $hostname = $server->getHostWithPort();

        // Determine protocol based on port
        $protocol = 'https';
        if ($server->port === 80 || app()->isLocal()) {
            $protocol = 'http';
        } elseif ($server->port === 443) {
            $protocol = 'https';
        }

        $client = $this->clients()
            ->whereNull('start')
            ->whereNull('stop')
            ->firstOrCreate([
                'server_id' => $server->id,
            ]);

        $data['hls_urls'] = [];
        $data['client_id'] = $client->id;

        // HLS URLs only
        $data['hls_urls']['master'] = $protocol."://$hostname/live/livestream.m3u8?streamkey=".$this->streamkey.'&client_id='.$client->id;
        foreach (['original', 'fhd', 'hd', 'sd', 'ld', 'audio_hd', 'audio_sd'] as $quality) {
            $qualityUrl = ($quality !== 'original') ? '_'.$quality : '';
            $data['hls_urls'][$quality] = $protocol."://$hostname/live/livestream$qualityUrl.m3u8?streamkey=".$this->streamkey.'&client_id='.$client->id;
        }

        return $data;
    }

    public function assignServerToUser(): bool
    {
        $server = Server::where('status', ServerStatusEnum::ACTIVE)
            ->where('type', ServerTypeEnum::EDGE)
            ->addSelect([
                'client_count' => Client::selectRaw('count(*)')
                    ->whereColumn('clients.server_id', 'servers.id')
                    ->connected(),
            ])
            ->groupBy('servers.id')
            ->orderBy('client_count',
                'desc') // Desc fill servers with most clients first, Asc fill servers with least clients first
            ->selectRaw('servers.id, max_clients as max_clients')
            ->havingRaw('client_count < max_clients')
            ->first();

        if (is_null($server) || is_null($server->id)) {
            // No available servers, clear any stale assignment
            if ($this->server_id || $this->streamkey) {
                $this->update(['server_id' => null, 'streamkey' => null]);
            }

            return false;
        }

        // Assign Server to User
        $this->update([
            'server_id' => $server->id,
            'streamkey' => Str::random(32),
            'is_provisioning' => false, // Clear provisioning flag on successful assignment
        ]);

        return true;
    }

    public function messages(): \Illuminate\Database\Eloquent\Relations\HasMany
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
     * Sync roles from login (registration system).
     */
    public function syncRolesFromLogin(array $rolesSlugs): void
    {
        // Log current roles before sync
        \Log::info('Before sync - User '.$this->id.' roles: ', $this->roles()->pluck('slug')->toArray());
        \Log::info('Syncing roles from login: ', $rolesSlugs);

        // Get IDs of roles that should be detached (only those with assigned_at_login = true)
        $roleIdsToDetach = $this->roles()
            ->where('assigned_at_login', true)
            ->pluck('roles.id')
            ->toArray();

        \Log::info('Roles to detach (IDs): ', $roleIdsToDetach);

        // Detach only those specific roles
        if (! empty($roleIdsToDetach)) {
            $this->roles()->detach($roleIdsToDetach);
            \Log::info('Detached roles with assigned_at_login=true');
        }

        // Add new roles from login
        $roles = Role::whereIn('slug', $rolesSlugs)
            ->where('assigned_at_login', true)
            ->get();

        \Log::info('Adding roles: ', $roles->pluck('slug')->toArray());

        foreach ($roles as $role) {
            $role->assignTo($this, null);
        }

        // Log final roles after sync
        \Log::info('After sync - User '.$this->id.' roles: ', $this->roles()->pluck('slug')->toArray());
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
     * Check if user can access Filament panel.
     */
    public function canAccessPanel(\Filament\Panel $panel): bool
    {
        return $this->hasPermission('filament.access') || $this->isStaff();
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

    /**
     * Get the user's badge for chat display.
     */
    public function getBadgeAttribute(): ?array
    {
        if (! $this->badge_type) {
            // Check roles for badge assignment
            if ($this->isAdmin()) {
                $this->badge_type = 'admin';
            } elseif ($this->isModerator()) {
                $this->badge_type = 'moderator';
            }
        }

        if (! $this->badge_type) {
            return null;
        }

        return [
            'type' => $this->badge_type,
            'label' => $this->getBadgeLabel(),
            'color' => $this->getBadgeColor(),
        ];
    }

    /**
     * Get badge label.
     */
    private function getBadgeLabel(): string
    {
        return match ($this->badge_type) {
            'subscriber_yellow', 'subscriber_purple' => 'S',
            'moderator' => 'MOD',
            'admin' => 'ADMIN',
            default => '',
        };
    }

    /**
     * Get badge color.
     */
    private function getBadgeColor(): string
    {
        return match ($this->badge_type) {
            'subscriber_yellow' => '#FFD700', // Gold
            'subscriber_purple' => '#9B59B6', // Purple
            'moderator' => '#00FF00', // Green
            'admin' => '#FF0000', // Red
            default => '#808080',
        };
    }
}
