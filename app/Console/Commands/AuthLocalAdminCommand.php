<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use App\Support\Manage\Settings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * The way back into an installation nobody can sign in to.
 *
 * The settings pane refuses a save that leaves no administrator a way in, but it
 * cannot help with the case it never sees: a provider endpoint that stops answering,
 * an installation restored without its OIDC client, a first boot with no accounts at
 * all. This creates or promotes a local administrator and switches password sign-in
 * on in the same breath, because an account nobody is allowed to use is not a
 * recovery.
 *
 * Same write as the panel, not a second source: the switch goes through the settings
 * registry, so /manage > Settings > Sign-in shows it on afterwards.
 */
class AuthLocalAdminCommand extends Command
{
    protected $signature = 'auth:local-admin
        {email : Address the account signs in with}
        {--name= : Display name, defaulting to the part before the @}
        {--password= : Skips the prompt; useful from a provisioning script}';

    protected $description = 'Create or promote a local administrator and switch password sign-in on';

    public function handle(Settings $settings): int
    {
        $email = (string) $this->argument('email');

        if (Validator::make(['email' => $email], ['email' => ['required', 'email']])->fails()) {
            $this->error("\"{$email}\" is not an email address.");

            return self::FAILURE;
        }

        $password = (string) ($this->option('password') ?? $this->secret('Password') ?? '');

        if (strlen($password) < 8) {
            $this->error('The password has to be at least 8 characters.');

            return self::FAILURE;
        }

        // Only accounts this installation holds. An account a provider owns keeps its
        // identity and is left alone, even when it carries the same address.
        $user = User::query()
            ->whereNull('sub')
            ->whereDoesntHave('identities')
            ->where('email', $email)
            ->first();

        if ($user === null) {
            $user = new User(['sub' => null, 'email' => $email]);
        }

        $user->fill([
            'name' => $this->option('name') ?: ($user->name ?: strtok($email, '@')),
            'password' => $password,
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();

        $user->assignBaselineRole();
        $this->administratorRole()->assignTo($user, null);

        $settings->save(['auth_local' => true]);

        $this->info("{$user->name} <{$email}> can sign in with a password and holds admin.access.");

        return self::SUCCESS;
    }

    /**
     * The role that carries admin.access, created if this installation has none -
     * which is the first-boot case, where there is nothing to promote against.
     */
    private function administratorRole(): Role
    {
        $existing = Role::query()
            ->orderByDesc('priority')
            ->get()
            ->first(fn (Role $role) => $role->hasPermission('admin.access'));

        return $existing ?? Role::create([
            'name' => 'Administrator',
            'slug' => 'admin',
            'description' => 'Full access to the management panel.',
            'permissions' => ['admin.access'],
            'priority' => 100,
        ]);
    }
}
