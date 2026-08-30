<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Models\AuthProvider;
use App\Models\Role;
use App\Services\Auth\OidcProvider;
use App\Services\Auth\ProviderFactory;
use App\Services\OpenIDService;
use App\Support\Auth\ProviderFlow;
use App\Support\Auth\ProviderTestReport;
use App\Support\Auth\RedirectUri;
use App\Support\AuthModes;
use App\Support\Manage\Action;
use App\Support\Manage\Column;
use App\Support\Manage\Settings;
use App\Support\Manage\Status;
use App\Support\Manage\Table;
use App\Support\Manage\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Response;

/**
 * The ways in that are not a password, one row each.
 *
 * A settings area whose contents are rows, like events and categories, and behind
 * admin.access rather than access-manage because a row here is a credential.
 *
 * Two things this page must never do, and both are enforced under a lock: leave the
 * installation with no way in an administrator can use, and delete a row accounts are
 * signed in through. The second is not a warning - the foreign key cascades, so one
 * click would take every identity on that provider with it and orphan hundreds of
 * accounts at once. Switching it off is the reversible version and is what is offered.
 */
class AuthProviderController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', AuthProvider::class);

        $table = Table::make(AuthProvider::query()->withCount('identities'))
            ->name('providers')
            ->columns([
                Column::text('label', 'Name')->searchable('label')->sortable(),
                Column::text('driver', 'Driver'),
                Column::copyable('callback', 'Callback URL'),
                Column::badge('state', 'State'),
                Column::number('identities_count', 'Accounts'),
            ])
            ->defaultSort('order', 'asc')
            ->rows(fn (AuthProvider $provider) => [
                'label' => $provider->label,
                'driver' => ProviderFactory::options()[$provider->driver] ?? $provider->driver,
                'callback' => $provider->redirectUrl(),
                'state' => $this->stateBadge($provider),
                'identities_count' => $provider->identities_count,
            ])
            ->recordUrl(fn (AuthProvider $provider) => route('manage.providers.edit', $provider))
            ->rowActions(fn (AuthProvider $provider) => $this->rowActions($provider))
            ->pageActions($this->pageActions());

        return inertia('Manage/Providers/Index', [
            'table' => $table->toArray($request),
            'navigation' => app(Settings::class)->navigation(),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', AuthProvider::class);

        return inertia('Manage/Providers/Form', [
            'navigation' => app(Settings::class)->navigation(),
            'provider' => null,
            'options' => $this->options(),
            'defaults' => [
                'driver' => 'oidc',
                'key' => '',
                'label' => '',
                'client_id' => '',
                'client_secret' => '',
                'issuer_url' => '',
                'endpoints' => [],
                'scopes' => [],
                'packages_url' => '',
                'enabled' => false,
                'order' => AuthProvider::max('order') + 1,
                'grants_baseline' => true,
                'role_map' => [],
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', AuthProvider::class);

        $validated = $this->validated($request);

        // Nothing to check: a new row is either off, or it is one more way in.
        $provider = AuthProvider::create($validated);

        Toast::flashSuccess('Provider added', "Register {$provider->redirectUrl()} as the callback URL.");

        return to_route('manage.providers.edit', $provider);
    }

    public function edit(AuthProvider $provider): Response
    {
        $this->authorize('view', $provider);

        $provider->loadCount('identities');

        return inertia('Manage/Providers/Form', [
            'navigation' => app(Settings::class)->navigation(),
            'provider' => $this->payload($provider),
            'options' => $this->options(),
            'actions' => array_map(
                fn (Action $action) => $action->toArray(),
                $this->rowActions($provider, includeEdit: false),
            ),
            'testUrl' => route('manage.providers.test', $provider),
            // The result of the last test this session ran, shown once. Deliberately
            // not stored on the row: it is what one provider said to one person at one
            // moment, and a stale copy of that on the page is worse than none.
            'test' => session('provider.test'),
        ]);
    }

    /**
     * Check a provider by using it, before anybody else is let near it.
     *
     * A real round trip to the real provider with the redirect URI that is actually
     * registered there, because the two mistakes this is for - a wrong secret and a
     * callback URL that does not match - are both invisible until one has happened.
     * The way back is App\Http\Controllers\Auth\ProviderCallbackController, which
     * stops short of writing anything.
     *
     * It works on a row that is switched off, since testing before enabling is the
     * whole point, and it is the only flow that steps past the usability gate.
     */
    public function test(Request $request, AuthProvider $provider): RedirectResponse
    {
        $this->authorize('update', $provider);

        if ($reason = $this->cannotBeTested($provider)) {
            return back()->with('provider.test', ProviderTestReport::failure($provider, $reason));
        }

        return ProviderFlow::start($request, $provider, ProviderFlow::TEST);
    }

    /**
     * Why the round trip is not worth starting, or null when it is.
     *
     * Answered before leaving wherever it can be. A discovery document that does not
     * resolve fails here rather than after a trip through the provider, which is the
     * difference between an answer and "it did not work".
     */
    private function cannotBeTested(AuthProvider $provider): ?string
    {
        if (! ProviderFactory::supports($provider->driver)) {
            return 'This installation has no driver for '.$provider->driver.'.';
        }

        if (blank($provider->client_id) || blank($provider->client_secret)) {
            return 'Fill in the client ID and the client secret first.';
        }

        if ($rejection = RedirectUri::rejection($provider->redirectUrl())) {
            return $rejection;
        }

        $driver = app(ProviderFactory::class)->make($provider);

        if (! $driver instanceof OidcProvider) {
            return null;
        }

        // Asked again rather than read from the hour-long cache: somebody testing has
        // usually just changed something at the provider, and an answer from before
        // they did is the one answer a test must not give.
        OpenIDService::forget($provider);

        try {
            // Resolved rather than assumed: an issuer that answers nothing, or a
            // document with no authorize endpoint in it, is the third thing operators
            // hit and it has no symptom until the button does nothing.
            $driver->endpoint('authorization_endpoint');
            $driver->endpoint('token_endpoint');
            $driver->endpoint('userinfo_endpoint');
        } catch (\Throwable $e) {
            return 'No usable discovery document at '.rtrim((string) $provider->issuer_url, '/')
                .'/.well-known/openid-configuration. Set the endpoints on this page instead.';
        }

        return null;
    }

    public function update(Request $request, AuthProvider $provider): RedirectResponse
    {
        $this->authorize('update', $provider);

        $validated = $this->validated($request, $provider);

        /*
         * Checked and written under one lock, exactly as the settings save is: two
         * administrators each switching a different provider off both passed their own
         * check against a state the other was about to change.
         */
        $refusal = DB::transaction(function () use ($provider, $validated) {
            AuthProvider::query()->lockForUpdate()->get();

            $after = (clone $provider)->forceFill($validated);

            if ($reason = AuthModes::providerLockout($provider, $after->isUsable())) {
                return $reason;
            }

            $provider->update($validated);

            return null;
        });

        if ($refusal !== null) {
            Toast::flashDanger('Provider not saved', $refusal);

            return back();
        }

        // The endpoints are keyed on the row, so a changed issuer must not answer from
        // the document the old one published.
        OpenIDService::forget($provider);

        Toast::flashSuccess('Provider saved');

        return back();
    }

    /**
     * Deleting takes every identity on this provider with it, by cascade, so a row
     * anybody signs in through is refused rather than warned about.
     */
    public function destroy(AuthProvider $provider): RedirectResponse
    {
        $this->authorize('delete', $provider);

        if ($provider->identities()->exists()) {
            Toast::flashDanger(
                'Provider not deleted',
                'Accounts sign in through it. Switch it off instead.',
            );

            return back();
        }

        $refusal = DB::transaction(function () use ($provider) {
            AuthProvider::query()->lockForUpdate()->get();

            if ($reason = AuthModes::providerLockout($provider, false)) {
                return $reason;
            }

            $provider->delete();

            return null;
        });

        if ($refusal !== null) {
            Toast::flashDanger('Provider not deleted', $refusal);

            return back();
        }

        OpenIDService::forget($provider);

        Toast::flashSuccess('Provider deleted');

        return to_route('manage.providers.index');
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(AuthProvider $provider): array
    {
        return [
            'id' => $provider->id,
            'driver' => $provider->driver,
            'key' => $provider->key,
            'label' => $provider->label,
            'client_id' => $provider->client_id,
            // Write-only, like every other secret in the panel: the mask means "leave
            // it", the sentinel means "delete it".
            'client_secret' => $provider->client_secret ? Settings::MASK_SECRET : '',
            'issuer_url' => $provider->issuer_url,
            'endpoints' => $provider->endpoints ?? [],
            'scopes' => $provider->scopes ?? [],
            'packages_url' => $provider->packages_url,
            'redirect_path' => $provider->redirect_path,
            'enabled' => $provider->enabled,
            'order' => $provider->order,
            'grants_baseline' => $provider->grants_baseline,
            'role_map' => $provider->role_map ?? [],
            'callback_url' => $provider->redirectUrl(),
            'identities_count' => $provider->identities_count ?? 0,
            'configured' => $provider->isConfigured(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function options(): array
    {
        return [
            'drivers' => ProviderFactory::options(),
            'matches' => ['exact' => 'Exact', 'contains' => 'Contains'],
            'roles' => Role::ordered()->get()->map(fn (Role $role) => [
                'id' => $role->id,
                'name' => $role->name,
            ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?AuthProvider $provider = null): array
    {
        $rules = [
            'driver' => ['required', 'string', Rule::in(array_keys(ProviderFactory::DRIVERS))],
            'key' => [
                'required', 'string', 'max:60', 'regex:/^[a-z0-9-]+$/',
                Rule::unique('auth_providers', 'key')->ignore($provider?->id),
            ],
            'label' => ['required', 'string', 'max:120'],
            'client_id' => ['nullable', 'string', 'max:255'],
            'client_secret' => ['nullable', 'string', 'max:1000'],
            'issuer_url' => ['nullable', 'url', 'max:2048'],
            'endpoints' => ['nullable', 'array'],
            'endpoints.*' => ['nullable', 'string', 'max:2048'],
            'scopes' => ['nullable', 'array'],
            'scopes.*' => ['string', 'max:120'],
            'packages_url' => ['nullable', 'url', 'max:2048'],
            'enabled' => ['boolean'],
            'order' => ['integer', 'min:0'],
            'grants_baseline' => ['boolean'],
            'role_map' => ['nullable', 'array'],
            'role_map.*.claim' => ['required', 'string', 'max:120'],
            'role_map.*.match' => ['required', Rule::in(['exact', 'contains'])],
            'role_map.*.value' => ['required', 'string', 'max:255'],
            'role_map.*.role_id' => ['required', 'integer', 'exists:roles,id'],
        ];

        $validated = $request->validate($rules, [], [
            'client_id' => 'client ID',
            'client_secret' => 'client secret',
            'issuer_url' => 'provider URL',
        ]);

        /*
         * Write-only, the same three answers as every secret in the settings pane:
         * blank or the mask leaves the stored one, the sentinel deletes it, anything
         * else replaces it.
         */
        $posted = is_string($validated['client_secret'] ?? null) ? trim($validated['client_secret']) : null;

        if ($posted === Settings::CLEAR_SECRET) {
            $validated['client_secret'] = null;
        } elseif ($posted === null || $posted === '' || $posted === Settings::MASK_SECRET) {
            unset($validated['client_secret']);
        }

        $validated['endpoints'] = array_filter($validated['endpoints'] ?? []) ?: null;
        $validated['scopes'] = array_values(array_filter($validated['scopes'] ?? [])) ?: null;
        $validated['role_map'] = array_values($validated['role_map'] ?? []) ?: null;

        return $validated;
    }

    /**
     * @return array<string, mixed>
     */
    private function stateBadge(AuthProvider $provider): array
    {
        if (! $provider->enabled) {
            return Status::make('Off', Status::IDLE, 'power-off');
        }

        if (! $provider->isConfigured()) {
            return Status::make('Incomplete', Status::WARN, 'alert-triangle');
        }

        return Status::make('On', Status::LIVE, 'key');
    }

    /**
     * @return array<int, Action>
     */
    private function rowActions(AuthProvider $provider, bool $includeEdit = true): array
    {
        $actions = $includeEdit
            ? [Action::link('edit', 'Edit', route('manage.providers.edit', $provider))->icon('pencil')]
            : [];

        if (request()->user()->can('delete', $provider)) {
            $actions[] = Action::delete('delete', 'Delete', route('manage.providers.destroy', $provider))
                ->icon('trash-2')
                ->tone(Status::DANGER)
                ->disabled($provider->identities()->exists() ? 'Accounts sign in through it. Switch it off instead.' : null)
                ->confirm(
                    'Delete provider',
                    "{$provider->label} stops being offered and its callback URL stops answering.",
                    'Delete',
                );
        }

        return $actions;
    }

    /**
     * @return array<int, Action>
     */
    private function pageActions(): array
    {
        if (! request()->user()->can('create', AuthProvider::class)) {
            return [];
        }

        return [
            Action::link('create', 'New Provider', route('manage.providers.create'))->icon('plus'),
        ];
    }
}
