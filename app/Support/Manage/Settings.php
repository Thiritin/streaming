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
     * Whether a toggle is shown as its own opposite.
     *
     * For the one flag whose honest question is the inverse of what it stores:
     * `auth_required` is asked as "Guest Access". The inversion is the page's alone -
     * save() turns it back before writing, the table keeps the column it always had,
     * and every reader of config('auth.required') is untouched. Anything that reads a
     * POSTED value has to un-invert it, which is why this is one declared flag rather
     * than a rule applied by eye.
     *
     * @param  array<string, mixed>  $field
     */
    public static function isInverted(array $field): bool
    {
        return ($field['type'] ?? null) === 'toggle' && (bool) ($field['invert'] ?? false);
    }

    /**
     * Whether a field is only on screen while another field holds one of a set of
     * values. Layout, not a gate: a hidden field still posts what it holds.
     *
     * @param  array<string, mixed>  $field
     * @return array{field: string, is: array<int, mixed>}|null
     */
    public static function visibleWhen(array $field): ?array
    {
        $rule = $field['visible_when'] ?? null;

        if (! is_array($rule) || ! isset($rule['field'])) {
            return null;
        }

        return [
            'field' => (string) $rule['field'],
            'is' => array_values((array) ($rule['is'] ?? [true])),
        ];
    }

    /**
     * Whether a card is on screen but inert, because another field on the pane has
     * made its contents moot. Layout, not a gate: the fields keep their saved values
     * and a disabled control still posts what it holds.
     *
     * @param  array<string, mixed>  $card
     * @return array{field: string, is: array<int, mixed>, description: string|null}|null
     */
    public static function inertWhen(array $card): ?array
    {
        $rule = $card['inert_when'] ?? null;

        if (! is_array($rule) || ! isset($rule['field'])) {
            return null;
        }

        return [
            'field' => (string) $rule['field'],
            'is' => array_values((array) ($rule['is'] ?? [true])),
            'description' => isset($rule['description']) ? (string) $rule['description'] : null,
        ];
    }

    /**
     * Groups with every field resolved to its current value, default and preview URL.
     *
     * A group's cards keep their shape for the page, and their fields appear in the
     * flat `fields` list as well: everything that validates or saves reads that list,
     * so a pane gaining cards changes what it looks like and nothing else.
     *
     * @return array<int, array<string, mixed>>
     */
    public function groups(): array
    {
        return array_map(function (array $group) {
            $cards = array_map(function (array $card) {
                $card['fields'] = array_map(fn (array $field) => $this->field($field), $card['fields'] ?? []);
                $card['note'] = $this->note($card);
                $card['inertWhen'] = self::inertWhen($card);

                return $card;
            }, $group['cards'] ?? []);

            $group['fields'] = array_map(fn (array $field) => $this->field($field), $group['fields'] ?? []);

            foreach ($cards as $card) {
                $group['fields'] = [...$group['fields'], ...$card['fields']];
            }

            $group['cards'] = $cards;
            $group['note'] = $this->note($group);

            return $group;
        }, config('settings.groups', []));
    }

    /**
     * The pane a dead pane's fields moved to, or null when the key was never one.
     *
     * A pane's URL is printed in the admin docs and pasted between operators, so a
     * merge answers a redirect rather than the 404 an unknown key gets.
     */
    public function movedTo(string $key): ?string
    {
        $target = config("settings.moved.{$key}");

        return is_string($target) && $this->group($target) !== null ? $target : null;
    }

    /**
     * A pane's or a card's note: one line of copy, optionally with a link beside it.
     * Nothing is saved by it, so it stays out of the field list.
     *
     * A note that names `url_config` takes its link from config rather than repeating
     * a URL the rest of the app already owns, and drops out entirely when that config
     * value is empty - which is how an installation with nothing published hides the
     * link instead of offering a dead one.
     *
     * @param  array<string, mixed>  $owner  A group or one of its cards.
     * @return array<string, mixed>|null
     */
    private function note(array $owner): ?array
    {
        $note = $owner['note'] ?? null;

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
     * The settings menu, grouped into sections in registry order.
     *
     * Sections rather than one flat list because the rail 256px to the left already
     * uses headings, so an undifferentiated column of panes was the odd one out in its
     * own panel. A pane names its section in the registry; Events and Categories are
     * rows rather than knobs, so they join Programme by hand.
     *
     * No blurbs. The heading carries the context they were reaching for, and six of the
     * fifteen restated the label they sat under.
     *
     * Answers one shape rather than two so every page that renders the menu - the
     * generated panes, Events, Categories, the providers - keeps passing one prop.
     *
     * @return array{sections: array<int, array{heading: string, items: array<int, array<string, mixed>>}>, reset: array{key: string, label: string, url: string}|null}
     */
    public function navigation(): array
    {
        $groups = config('settings.groups', []);
        $first = $groups[0]['key'] ?? null;

        $sections = [];

        foreach ($groups as $group) {
            // Reset is a destructive button, not a pane to browse: it comes back on its
            // own so the menu can pin it under a divider rather than list it as a peer.
            if (($group['action'] ?? null) === 'reset' || ! isset($group['section'])) {
                continue;
            }

            $sections[$group['section']][] = [
                'key' => $group['key'],
                'label' => $group['label'],
                'icon' => $group['icon'] ?? 'cog',
                // The bare /manage/settings is the first pane rather than a redirect.
                'url' => $group['key'] === $first
                    ? route('manage.settings')
                    : route('manage.settings.group', $group['key']),
            ];
        }

        /*
         * Events first: the calendar decides whether the front page is a programme or
         * the archive, so it is the more consequential of the two. Both lead Programme,
         * ahead of the connection the programme is imported through.
         */
        array_unshift(
            $sections['Programme'],
            [
                'key' => 'events',
                'label' => 'Events',
                'icon' => 'calendar',
                'url' => route('manage.events.index'),
            ],
            [
                'key' => 'categories',
                'label' => 'Categories',
                'icon' => 'tags',
                'url' => route('manage.categories.index'),
            ],
        );

        return [
            'sections' => array_map(
                fn (string $heading, array $items) => ['heading' => $heading, 'items' => $items],
                array_keys($sections),
                $sections,
            ),
            'reset' => $this->resetPane(),
        ];
    }

    /**
     * The reset pane, which the menu pins under a divider rather than listing.
     *
     * It keeps its URL, so a bookmark still opens it; it just stops being a row of equal
     * weight to Chat.
     *
     * @return array{key: string, label: string, url: string}|null
     */
    public function resetPane(): ?array
    {
        foreach (config('settings.groups', []) as $group) {
            if (($group['action'] ?? null) === 'reset') {
                return [
                    'key' => $group['key'],
                    'label' => $group['label'],
                    'url' => route('manage.settings.group', $group['key']),
                ];
            }
        }

        return null;
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
            $rules['values.'.$field['key']] = self::fieldRules($field);

            // A repeater validates its rows too, one rule set per column.
            foreach ($field['itemRules'] ?? [] as $column => $columnRules) {
                $rules["values.{$field['key']}.*.{$column}"] = $columnRules;
            }
        }

        return $rules;
    }

    /**
     * A field's rules, with `required` made conditional on whatever puts the field on
     * screen. A pane must not fail on a control the person saving it never saw.
     *
     * @param  array<string, mixed>  $field
     * @return array<int, mixed>
     */
    private static function fieldRules(array $field): array
    {
        $rules = $field['rules'] ?? ['nullable', 'string'];
        $visible = self::visibleWhen($field);

        if ($visible === null || ! in_array('required', $rules, true)) {
            return $rules;
        }

        $values = array_map(
            fn (mixed $value) => is_bool($value) ? ($value ? 'true' : 'false') : (string) $value,
            $visible['is'],
        );

        $rules = array_map(
            fn (mixed $rule) => $rule === 'required'
                ? 'required_if:values.'.$visible['field'].','.implode(',', $values)
                : $rule,
            $rules,
        );

        // Nullable with it, because the same field is empty whenever it is off screen and
        // ConvertEmptyStringsToNull has already turned that into null. Without this the
        // `string` beside the old `required` fails on every credential belonging to a
        // driver nobody chose, which is the failure the rewrite exists to prevent.
        return in_array('nullable', $rules, true) ? $rules : ['nullable', ...$rules];
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

            // A field the pane did not show is not a field anybody cleared. A blank one
            // otherwise deletes its stored row, so switching a driver and saving wiped
            // every credential belonging to the driver being switched away from - the
            // same reasoning fieldRules() already applies to `required`.
            if (! self::isVisibleIn($field, $values)) {
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

                // The page showed the opposite, so this is the opposite of what is stored.
                if (self::isInverted($field)) {
                    $value = ! $value;
                }

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
     * Whether this field is on screen for the values being saved.
     *
     * The posted value of the controlling field wins, because switching a driver and
     * filling in its credentials is one save and the answer has to follow the request.
     * A payload that leaves the controlling field out falls back to what is stored
     * rather than assuming visible: assuming would let a hand-crafted partial PUT clear
     * a hidden field's row, and "every controlling field carries `required`, so group
     * validation rejects that payload first" is an invariant nothing states and nothing
     * enforces.
     *
     * @param  array<string, mixed>  $field
     * @param  array<string, mixed>  $values
     */
    private static function isVisibleIn(array $field, array $values): bool
    {
        $visible = self::visibleWhen($field);

        if ($visible === null) {
            return true;
        }

        $current = array_key_exists($visible['field'], $values)
            ? $values[$visible['field']]
            : self::storedValueOf($visible['field']);

        foreach ($visible['is'] as $expected) {
            if (is_bool($expected) ? self::toBool($current) === $expected : (string) $current === (string) $expected) {
                return true;
            }
        }

        return false;
    }

    /**
     * What a field holds right now, in the same terms the page would post it.
     *
     * Only for resolving a `visible_when` whose controlling field was not sent. An
     * inverted toggle is flipped back, because the page posts what it showed and this
     * reads what was stored.
     */
    private static function storedValueOf(string $key): mixed
    {
        foreach (config('settings.groups', []) as $group) {
            foreach (self::declaredFields($group) as $field) {
                if ($field['key'] !== $key) {
                    continue;
                }

                $value = BrandingSetting::getValue($key, self::defaultOf($field));

                if (($field['type'] ?? null) === 'toggle') {
                    $value = self::toBool($value);

                    return self::isInverted($field) ? ! $value : $value;
                }

                return $value;
            }
        }

        return null;
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
            ->flatMap(fn (array $registered) => self::declaredFields($registered))
            ->all();
    }

    /**
     * One group's fields as declared, cards flattened back into the list they would
     * have been without them. Everything that validates or saves comes through here,
     * which is what keeps cards a matter of layout.
     *
     * @param  array<string, mixed>  $group
     * @return array<int, array<string, mixed>>
     */
    public static function declaredFields(array $group): array
    {
        $fields = $group['fields'] ?? [];

        foreach ($group['cards'] ?? [] as $card) {
            $fields = [...$fields, ...($card['fields'] ?? [])];
        }

        return $fields;
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

            if (self::isInverted($field)) {
                $value = ! $value;
                $default = ! $default;
            }
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
                'visibleWhen' => self::visibleWhen($field),
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
            // Which other field on the pane decides whether this one is on screen.
            'visibleWhen' => self::visibleWhen($field),
            // A control that is an option on the row above it rather than a peer.
            'indent' => (bool) ($field['indent'] ?? false),
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
