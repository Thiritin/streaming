<?php

namespace App\Models;

use App\Notifications\ResetPassword;
use App\Notifications\VerifyEmail;
use App\Support\NotificationCategories;
use App\Support\NotificationScope;
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
     * The column defaults, repeated here because Eloquent does not read them off the
     * schema: a freshly created account would otherwise answer null for its scopes and
     * read as "notifications off" until it was reloaded from the database.
     */
    protected $attributes = [
        'notify_shows_live' => NotificationScope::SUBSCRIBED,
        'notify_recordings' => NotificationScope::SUBSCRIBED,
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'remember_token',
        // A whole user is serialised into chat payloads and Inertia props in more
        // places than is worth auditing; the address is only ever wanted where it is
        // asked for by name.
        'email',
        'telegram_chat_id',
        'streamkey',
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
        'notification_channels' => 'array',
        'telegram_linked_at' => 'datetime',
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
     * Both sign-in mails go out on a queue rather than inside the request that asked
     * for them: the row - a reset token, an account - is already written by the time
     * the message is built, so an installation whose mail is down should not answer
     * 500 on a request that half succeeded.
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPassword($token));
    }

    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new VerifyEmail);
    }

    /**
     * Every provider this account can sign in through.
     */
    public function identities(): HasMany
    {
        return $this->hasMany(UserIdentity::class);
    }

    /**
     * How many ways there are into this account: one per identity, plus a password.
     *
     * What "never disconnect your last way in" is measured against, and what an
     * administrator clearing a password is held to as well.
     *
     * The legacy `sub` counts while the column is still there: an account backfilled
     * before this release holds an identity, but one made by a test or a seeder from
     * the column alone still signs in through the provider.
     */
    public function signInMethodCount(): int
    {
        $identities = $this->identities()->count();

        if ($identities === 0 && $this->sub !== null) {
            $identities = 1;
        }

        return $identities + ($this->password === null ? 0 : 1);
    }

    /**
     * An account this installation holds itself, rather than one a provider owns.
     * No identity and no subject; that is the whole difference.
     */
    public function isLocal(): bool
    {
        return $this->sub === null && $this->identities()->doesntExist();
    }

    /**
     * Give this account the baseline role, if the installation declares one.
     *
     * A sign-in through a provider gets it from that provider's `grants_baseline`,
     * applied by App\Support\Auth\ProviderRoles. A local account has no provider to
     * hand it one, so it is attached as the account is created and the two kinds end
     * up the same citizen. Nothing else is granted automatically: any further
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

    /**
     * Shows this viewer asked to be told about.
     */
    public function showSubscriptions(): HasMany
    {
        return $this->hasMany(ShowSubscription::class);
    }

    public function subscribedShows()
    {
        return $this->belongsToMany(Show::class, 'show_subscriptions')->withTimestamps();
    }

    public function notificationDeliveries(): HasMany
    {
        return $this->hasMany(NotificationDelivery::class)->latest('id');
    }

    public function subscribesTo(Show $show): bool
    {
        return $this->showSubscriptions()->where('show_id', $show->id)->exists();
    }

    /**
     * Where mail goes. Null is a viewer the identity provider gave us no address for,
     * and Laravel skips the mail channel rather than throwing.
     */
    public function routeNotificationForMail(): ?string
    {
        return $this->email ?: null;
    }

    public function routeNotificationForTelegram(): ?string
    {
        return $this->telegram_chat_id ?: null;
    }

    public function hasTelegramLink(): bool
    {
        return (bool) $this->telegram_chat_id;
    }

    /**
     * The transports this viewer can actually be reached on, in the order they are
     * offered on the settings page.
     *
     * @return array<int, string>
     */
    public function availableNotificationChannels(): array
    {
        return array_values(array_filter([
            $this->email ? 'mail' : null,
            $this->telegram_chat_id ? 'telegram' : null,
        ]));
    }

    /**
     * What a notification for this viewer should be sent over.
     *
     * A null preference means every transport they have. That is deliberately not the
     * same as an empty one: somebody who pressed the bell in the archive without ever
     * opening settings asked to be told, not to pick a transport, whereas somebody who
     * cleared both boxes asked for nothing.
     *
     * @return array<int, string>
     */
    public function notificationChannels(): array
    {
        $available = $this->availableNotificationChannels();
        $chosen = $this->notification_channels;

        if ($chosen === null) {
            return $available;
        }

        return array_values(array_intersect($available, array_map('strval', $chosen)));
    }

    /**
     * How wide a category is drawn for this viewer: off, subscribed or any.
     */
    public function notificationScope(string $category): string
    {
        $column = NotificationCategories::column($category);

        return $column ? (string) ($this->{$column} ?? NotificationScope::OFF) : NotificationScope::OFF;
    }

    /**
     * Whether this viewer is subscribed to anything at all. What the counts on the
     * users page report, and what "unsubscribe from everything" leaves behind.
     *
     * A category set to `subscribed` with no show followed is not a subscription: it
     * is the shipped default sitting there waiting for somebody to press a bell.
     */
    public function wantsNotifications(): bool
    {
        foreach (NotificationCategories::keys() as $category) {
            if ($this->notificationScope($category) === NotificationScope::ANY) {
                return true;
            }
        }

        return $this->showSubscriptions()->exists();
    }

    /**
     * Stop everything: both categories and every show followed. Used by the
     * "unsubscribe from all" link in an email footer, which has to mean all of it or
     * the next message makes a liar of it.
     */
    public function unsubscribeFromEverything(): void
    {
        $this->forceFill([
            'notify_shows_live' => NotificationScope::OFF,
            'notify_recordings' => NotificationScope::OFF,
        ])->save();

        $this->showSubscriptions()->delete();
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
