<?php

namespace App\Support\Manage;

use App\Models\BrandingSetting;
use App\Support\ColorPresets;
use App\Support\ImportCli;
use App\Support\RuntimeConfig;
use Illuminate\Support\Facades\Storage;

/**
 * Reads config/settings.php and turns it into something the page can render, the
 * request can validate against, and the controller can save.
 *
 * Values live in the settings table (BrandingSetting) as flat key/value rows; a key
 * with no row falls back to its config default, so a fresh install boots with the
 * shipped copy and "reset" is a delete rather than a write.
 */
final class Settings
{
    /**
     * What a secret field posts to mean "delete the saved value". A blank secret means
     * "keep what is stored", because the page never receives the stored value to send
     * back, so an untouched form would otherwise wipe the token on every save.
     */
    public const CLEAR_SECRET = '__clear__';

    /**
     * What a stored secret looks like on the page. The field is filled so it is obvious
     * that something is saved; posting it back unchanged means "keep it".
     */
    public const MASK_SECRET = '••••••••';

    /**
     * The config path a field overrides at runtime. Named by the field when it
     * overrides something outside its own store, otherwise `{store}.{key}`.
     *
     * @param  array<string, mixed>  $field
     */
    public static function configPath(array $field): string
    {
        if (isset($field['config'])) {
            return $field['config'];
        }

        $store = $field['store'] ?? config('settings.store', 'branding');

        return "{$store}.{$field['key']}";
    }

    /**
     * A field's shipped default, which is whatever stands at the path it overrides.
     *
     * One path for both directions, so a key whose flat name differs from its real
     * config name does not have to duplicate its default into a second config file
     * for the panel to read. Everything that decides "is this the default" - the page,
     * the save, the command - has to come through here or a value equal to the default
     * would be written by one path and deleted by another.
     *
     * @param  array<string, mixed>  $field
     */
    public static function defaultOf(array $field): mixed
    {
        return RuntimeConfig::shipped(self::configPath($field));
    }

    /**
     * How a field's stored string becomes a config value. Toggles and repeaters
     * carry their own answer; everything else is a string unless it says otherwise.
     *
     * @param  array<string, mixed>  $field
     */
    public static function castOf(array $field): string
    {
        return $field['cast'] ?? match ($field['type'] ?? null) {
            'toggle' => 'bool',
            'links' => 'array',
            default => 'string',
        };
    }

    /**
     * Whether a field is stored encrypted at rest.
     *
     * @param  array<string, mixed>  $field
     */
    public static function isSecure(array $field): bool
    {
        return (bool) ($field['secure'] ?? false);
    }

    /**
     * Groups with every field resolved to its current value, default and preview URL.
     *
     * @return array<int, array<string, mixed>>
     */
    public function groups(): array
    {
        return array_map(function (array $group) {
            $group['fields'] = array_map(fn (array $field) => $this->field($field), $group['fields']);
            $group['note'] = $this->note($group);

            return $group;
        }, config('settings.groups', []));
    }

    /**
     * A pane's note: one line of copy, optionally with a link beside it. Nothing is
     * saved by it, so it stays out of the field list.
     *
     * A note that names `url_config` takes its link from config rather than repeating
     * a URL the rest of the app already owns, and drops out entirely when that config
     * value is empty - which is how an installation with nothing published hides the
     * link instead of offering a dead one.
     *
     * @param  array<string, mixed>  $group
     * @return array<string, mixed>|null
     */
    private function note(array $group): ?array
    {
        $note = $group['note'] ?? null;

        if (! is_array($note)) {
            return null;
        }

        // A note that names `downloads_config` offers one link per platform, built from the
        // base URL that config key holds. Same reasoning as `url_config`: the panel should
        // not repeat a URL a release already decides.
        if (isset($note['downloads_config'])) {
            $downloads = ImportCli::downloads(config($note['downloads_config']));

            if ($downloads === []) {
                return null;
            }

            $note['downloads'] = $downloads;
            unset($note['downloads_config']);

            return $note;
        }

        if (isset($note['url_config'])) {
            $url = trim((string) config($note['url_config']));

            if ($url === '') {
                return null;
            }

            $note['url'] = $url;
            unset($note['url_config']);
        }

        return $note;
    }

    /**
     * One resolved group, or null when nothing in the registry has that key. Each
     * group is a pane of its own at /manage/settings/{key}, so this is what a page
     * request renders; the rest of the registry only supplies the menu.
     *
     * @return array<string, mixed>|null
     */
    public function group(string $key): ?array
    {
        foreach ($this->groups() as $group) {
            if ($group['key'] === $key) {
                return $group;
            }
        }

        return null;
    }

    /**
     * The key of the first group, which is the pane /manage/settings itself shows.
     */
    public function firstGroupKey(): ?string
    {
        return config('settings.groups.0.key');
    }

    /**
     * The settings menu, in registry order: what each pane is called, what it is for
     * and where it lives.
     *
     * @return array<int, array{key: string, label: string, blurb: ?string, icon: ?string}>
     */
    public function navigation(): array
    {
        $groups = config('settings.groups', []);
        $first = $groups[0]['key'] ?? null;

        $panes = array_map(fn (array $group) => [
            'key' => $group['key'],
            'label' => $group['label'],
            'blurb' => $group['blurb'] ?? $group['description'] ?? null,
            'action' => $group['action'] ?? null,
            'icon' => $group['icon'] ?? 'cog',
            // The bare /manage/settings is the first pane rather than a redirect.
            'url' => $group['key'] === $first
                ? route('manage.settings')
                : route('manage.settings.group', $group['key']),
        ], $groups);

        /*
         * Events and categories are settings areas too, but sets of rows rather than
         * sets of knobs, so the registry cannot generate them. They join the menu by
         * hand and render their own pages inside the same shell.
         *
         * Events first: the calendar decides whether the front page is a programme or
         * the archive, so it is the more consequential of the two.
         */
        $rowPanes = [
            [
                'key' => 'events',
                'label' => 'Events',
                'blurb' => 'Convention dates',
                'action' => null,
                'icon' => 'calendar',
                'url' => route('manage.events.index'),
            ],
            [
                'key' => 'categories',
                'label' => 'Categories',
                'blurb' => 'Programme labels',
                'action' => null,
                'icon' => 'tags',
                'url' => route('manage.categories.index'),
            ],
        ];

        // Ahead of the reset pane, which throws every saved value away and stays last.
        $reset = array_search('reset', array_column($panes, 'action'), true);

        if ($reset === false) {
            return [...$panes, ...$rowPanes];
        }

        array_splice($panes, $reset, 0, $rowPanes);

        return $panes;
    }

    /**
     * Validation rules for an update, keyed as the form posts them.
     *
     * Scoped to one group when a key is given, because a pane posts only its own
     * fields: applying the whole registry's rules to it would fail every `required`
     * belonging to a pane that was never on screen.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(?string $group = null): array
    {
        $rules = [];

        foreach ($this->fields($group) as $field) {
            $rules['values.'.$field['key']] = $field['rules'] ?? ['nullable', 'string'];

            // A repeater validates its rows too, one rule set per column.
            foreach ($field['itemRules'] ?? [] as $column => $columnRules) {
                $rules["values.{$field['key']}.*.{$column}"] = $columnRules;
            }
        }

        return $rules;
    }

    /**
     * Field labels, so a validation message names the control rather than the key.
     *
     * @return array<string, string>
     */
    public function attributes(?string $group = null): array
    {
        $attributes = [];

        foreach ($this->fields($group) as $field) {
            $attributes['values.'.$field['key']] = strtolower($field['label']);

            foreach (array_keys($field['itemRules'] ?? []) as $column) {
                $attributes["values.{$field['key']}.*.{$column}"] = $column;
            }
        }

        return $attributes;
    }

    /**
     * Save the posted values, ignoring anything not declared in the registry.
     *
     * A value equal to the shipped default deletes its row rather than writing
     * one, so "use the default" really does hand the key back to
     * config/branding.php instead of pinning today's default into the database.
     *
     * @param  array<string, mixed>  $values
     */
    public function save(array $values): void
    {
        foreach ($this->fields() as $field) {
            if (! array_key_exists($field['key'], $values)) {
                continue;
            }

            $value = $values[$field['key']];
            $value = is_string($value) ? trim($value) : $value;

            if (($field['type'] ?? null) === 'password') {
                $this->saveSecret($field, $value);

                continue;
            }

            if (($field['type'] ?? null) === 'links') {
                $value = $this->cleanRows($value, array_keys($field['itemRules'] ?? []));
            }

            // A toggle is stored as '1' or '0', because the settings table holds
            // strings; false has to survive matchesDefault as a real value rather
            // than as the empty string it would otherwise look like.
            if (($field['type'] ?? null) === 'toggle') {
                $value = (bool) $value;

                if ($value === (bool) self::defaultOf($field)) {
                    BrandingSetting::where('key', $field['key'])->get()->each->delete();

                    continue;
                }

                BrandingSetting::setValue($field['key'], $value ? '1' : '0', $field['helper'] ?? null);

                continue;
            }

            if ($this->matchesDefault($value, self::defaultOf($field))) {
                BrandingSetting::where('key', $field['key'])->get()->each->delete();

                continue;
            }

            BrandingSetting::setValue(
                $field['key'],
                is_array($value) ? json_encode($value) : $value,
                $field['helper'] ?? null,
                self::isSecure($field),
            );
        }
    }

    /**
     * A secret is write-only: blank keeps the stored value, the clear sentinel deletes
     * it, and anything else replaces it.
     *
     * @param  array<string, mixed>  $field
     */
    private function saveSecret(array $field, mixed $value): void
    {
        // Blank, or the mask the page was given, both mean "leave the stored one alone".
        if ($value === null || $value === '' || $value === self::MASK_SECRET) {
            return;
        }

        if ($value === self::CLEAR_SECRET) {
            BrandingSetting::where('key', $field['key'])->get()->each->delete();

            return;
        }

        BrandingSetting::setValue($field['key'], $value, $field['helper'] ?? null, self::isSecure($field));
    }

    /**
     * A cleared field counts as "back to the default", not as a stored blank.
     *
     * ConvertEmptyStringsToNull rewrites an emptied input to null before it ever
     * reaches here, and BrandingSetting::getValue reads a null row as unset, so
     * a blank row could never win over a non-empty default anyway. Deleting says
     * the same thing without leaving a row that claims otherwise.
     */
    private function matchesDefault(mixed $value, mixed $default): bool
    {
        if ($value === null || $value === '' || $value === []) {
            return true;
        }

        return $value === $default;
    }

    /**
     * Drop repeater rows that are blank or missing a column, and keep only the
     * declared columns, so a hand-crafted post cannot smuggle extra keys into
     * the stored JSON. Order is preserved; that is what the footer renders by.
     *
     * @param  array<int, string>  $columns
     * @return array<int, array<string, string>>
     */
    private function cleanRows(mixed $rows, array $columns): array
    {
        if (! is_array($rows) || $columns === []) {
            return [];
        }

        $clean = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $values = [];

            foreach ($columns as $column) {
                $value = $row[$column] ?? null;
                $values[$column] = is_string($value) ? trim($value) : '';
            }

            // All or nothing: a row missing either half is an unfinished edit,
            // not a link.
            if (in_array('', $values, true)) {
                continue;
            }

            $clean[] = $values;
        }

        return $clean;
    }

    /**
     * Drop every saved value so the config defaults apply again. Uploaded files are
     * left on the disk: another installation setting may still point at them.
     */
    public function reset(): void
    {
        // One delete per row so the model's cache-clearing hook fires for each key.
        BrandingSetting::query()->get()->each->delete();
    }

    /**
     * Every registered field, or only one group's.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fields(?string $group = null): array
    {
        return collect(config('settings.groups', []))
            ->when($group !== null, fn ($groups) => $groups->where('key', $group))
            ->flatMap(fn (array $registered) => $registered['fields'])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $field
     * @return array<string, mixed>
     */
    private function field(array $field): array
    {
        $default = self::defaultOf($field);
        $value = BrandingSetting::getValue($field['key'], $default);

        // Repeaters are stored as JSON but edited as rows.
        if ($field['type'] === 'links') {
            $value = self::decodeRows($value);
            $default = self::decodeRows($default);
        }

        // Toggles come back from the table as '1' or '0' and are edited as booleans.
        if ($field['type'] === 'toggle') {
            $value = self::toBool($value);
            $default = (bool) $default;
        }

        // A password is never sent to the browser: a stored one is represented by the mask,
        // which the save side reads back as "unchanged". A `secret` is the other case -
        // a value this installation hands out rather than one an operator pastes in - so
        // it goes to the page in full and falls through to the generic branch below.
        if ($field['type'] === 'password') {
            $stored = is_string($value) && trim($value) !== '';

            return [
                'key' => $field['key'],
                'label' => $field['label'],
                'type' => 'password',
                'helper' => $field['helper'] ?? null,
                'secure' => self::isSecure($field),
                'purpose' => null,
                'accept' => null,
                'previewFit' => 'cover',
                'full' => $field['full'] ?? false,
                'rows' => null,
                'presets' => null,
                'options' => null,
                'required' => in_array('required', $field['rules'] ?? [], true),
                'value' => $stored ? self::MASK_SECRET : '',
                'default' => '',
                'hasValue' => $stored,
                'overridden' => $stored,
                'previewUrl' => null,
            ];
        }

        return [
            'key' => $field['key'],
            'label' => $field['label'],
            'type' => $field['type'],
            'helper' => $field['helper'] ?? null,
            // Whether the stored value is encrypted at rest, which is also the cue
            // not to print it back on the command line.
            'secure' => self::isSecure($field),
            'purpose' => $field['purpose'] ?? null,
            // What the file picker offers. Field-driven for the few uploads whose
            // types are narrower than the whole of image/*.
            'accept' => $field['accept'] ?? null,
            // 'contain' for a mark that must be seen whole; the default crops to fill.
            'previewFit' => $field['preview_fit'] ?? 'cover',
            'full' => $field['full'] ?? false,
            // Height of a textarea, for the few fields that expect paragraphs.
            'rows' => $field['rows'] ?? null,
            // Colour fields may offer a swatch row; hex => label, in order.
            'presets' => $this->presets($field),
            // Select fields carry their choices as a list, so the order survives.
            'options' => $this->options($field),
            'required' => in_array('required', $field['rules'] ?? [], true),
            'value' => $value,
            'default' => $default,
            // What a secret says when nothing is stored, and when it has been changed but
            // not yet saved. Field-driven because the consequence differs per key: an empty
            // control key switches the control API off, an empty import key switches
            // importing off, and each has its own set of people to re-key afterwards.
            'emptyNote' => $field['empty_note'] ?? null,
            'dirtyNote' => $field['dirty_note'] ?? null,
            // Whether anything is set at all, which a secret renders differently from
            // a blank text field.
            'hasValue' => is_string($value) ? trim($value) !== '' : ! empty($value),
            // Whether this key is currently overriding the shipped default.
            'overridden' => $value !== $default,
            'previewUrl' => in_array($field['type'], ['image', 'video'], true)
                ? $this->assetUrl($value)
                : null,
        ];
    }

    /**
     * A stored repeater value as rows. Accepts the JSON string the table holds
     * and an already-decoded array (the config default), and answers with an
     * empty list for anything unparseable, so one bad row can never break the
     * settings page or the footer.
     *
     * @return array<int, array<string, string>>
     */
    public static function decodeRows(mixed $value): array
    {
        if (is_array($value)) {
            return array_values($value);
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? array_values($decoded) : [];
    }

    /**
     * A stored toggle, which the table holds as a string, as a boolean. '0' is
     * off; anything else that is set is on.
     */
    public static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $value;
    }

    /**
     * Select choices as a list of {value, label}. Declared as value => label in the
     * registry, because that is what the `in:` rule beside them reads.
     *
     * @param  array<string, mixed>  $field
     * @return array<int, array{value: string, label: string}>|null
     */
    private function options(array $field): ?array
    {
        if (($field['type'] ?? null) !== 'select') {
            return null;
        }

        return collect($field['options'] ?? [])
            ->map(fn ($label, $value) => ['value' => (string) $value, 'label' => (string) $label])
            ->values()
            ->all();
    }

    /**
     * Preset swatches as a list, so the order survives the trip to the frontend.
     *
     * @param  array<string, mixed>  $field
     * @return array<int, array{hex: string, label: string}>|null
     */
    private function presets(array $field): ?array
    {
        if (empty($field['presets'])) {
            return null;
        }

        return ColorPresets::forFrontend();
    }

    /**
     * Same resolution BrandingService uses: absolute URLs and rooted paths pass
     * through, anything else is a path on the bucket, stored with public visibility.
     */
    private function assetUrl(mixed $path): ?string
    {
        $path = is_string($path) ? trim($path) : '';

        if ($path === '') {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://') || str_starts_with($path, '/')) {
            return $path;
        }

        // Same guard as BrandingService::assetUrl: an unconfigured bucket should not
        // turn the settings page into a 500.
        try {
            return Storage::disk('s3')->url($path);
        } catch (\Throwable) {
            return null;
        }
    }
}
