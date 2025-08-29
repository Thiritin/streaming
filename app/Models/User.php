<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enum\ServerStatusEnum;
use App\Enum\ServerTypeEnum;
use App\Enum\UserLevelEnum;
use App\Events\UserWaitingForProvisioningEvent;
use Filament\Models\Contracts\FilamentUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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

    protected $appends = ["role", "chat_color"];

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

        $hostname = $server->hostname;

        $client = $this->clients()
            ->whereNull('start')
            ->whereNull('stop')
            ->firstOrCreate([
                'server_id' => $server->id,
            ]);

        $data['hls_urls'] = [];
        $data['client_id'] = $client->id;
        $protocol = app()->isLocal() ? 'http' : 'https';
        
        // HLS URLs only
        $data['hls_urls']['master'] = $protocol."://$hostname/live/livestream.m3u8?streamkey=".$this->streamkey."&client_id=".$client->id;
        foreach (['original', 'fhd', 'hd', 'sd', 'ld', 'audio_hd', 'audio_sd'] as $quality) {
            $qualityUrl = ($quality !== 'original') ? "_".$quality : "";
            $data['hls_urls'][$quality] = $protocol."://$hostname/live/livestream$qualityUrl.m3u8?streamkey=".$this->streamkey."&client_id=".$client->id;
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
                    ->connected()
            ])
            ->groupBy('servers.id')
            ->orderBy('client_count',
                'desc') // Desc fill servers with most clients first, Asc fill servers with least clients first
            ->selectRaw("servers.id, max_clients as max_clients")
            ->havingRaw('client_count < max_clients')
            ->first();

        if (is_null($server) || is_null($server->id)) {
            if ($this->is_provisioning === false) {
                UserWaitingForProvisioningEvent::dispatch($this);
            }
            $this->update(['server_id' => null, 'streamkey' => null]);
            return false;
        }

        // Assign Server to User
        $this->update([
            'server_id' => $server->id,
            'streamkey' => Str::random(32),
        ]);

        if ($this->is_provisioning) {
            $this->update(['is_provisioning' => false]);
        }

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
            ->withPivot('assigned_at', 'expires_at', 'assigned_by')
            ->withTimestamps();
    }

    /**
     * Get active roles (non-expired).
     */
    public function activeRoles()
    {
        return $this->roles()
            ->where(function ($query) {
                $query->whereNull('role_user.expires_at')
                    ->orWhere('role_user.expires_at', '>', now());
            });
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
    public function assignRole($role, ?string $assignedBy = 'system', ?\DateTime $expiresAt = null): void
    {
        if (is_string($role)) {
            $role = Role::where('slug', $role)->first();
        }

        if ($role) {
            $role->assignTo($this, $assignedBy, $expiresAt);
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
        // Remove all roles that are assigned at login
        $this->roles()->where('assigned_at_login', true)->detach();

        // Add new roles from login
        $roles = Role::whereIn('slug', $rolesSlugs)
            ->where('assigned_at_login', true)
            ->get();

        foreach ($roles as $role) {
            $role->assignTo($this, 'login');
        }
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
     * Check if user is staff (admin or moderator).
     */
    public function isStaff(): bool
    {
        return $this->hasAnyRole(['admin', 'moderator']) || 
               $this->activeRoles()->where('is_staff', true)->exists();
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
}
