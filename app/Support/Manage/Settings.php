<?php

namespace App\Support\Manage;

use App\Models\BrandingSetting;
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
     * Groups with every field resolved to its current value, default and preview URL.
     *
     * @return array<int, array<string, mixed>>
     */
    public function groups(): array
    {
        return array_map(function (array $group) {
            $group['fields'] = array_map(fn (array $field) => $this->field($field), $group['fields']);

            return $group;
        }, config('settings.groups', []));
    }

    /**
     * Validation rules for an update, keyed as the form posts them.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = [];

        foreach ($this->fields() as $field) {
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
    public function attributes(): array
    {
        $attributes = [];

        foreach ($this->fields() as $field) {
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

                if ($value === (bool) config(($field['store'] ?? config('settings.store', 'branding')).'.'.$field['key'])) {
                    BrandingSetting::where('key', $field['key'])->get()->each->delete();

                    continue;
                }

                BrandingSetting::setValue($field['key'], $value ? '1' : '0', $field['helper'] ?? null);

                continue;
            }

            $store = $field['store'] ?? config('settings.store', 'branding');

            if ($this->matchesDefault($value, config("{$store}.{$field['key']}"))) {
                BrandingSetting::where('key', $field['key'])->get()->each->delete();

                continue;
            }

            BrandingSetting::setValue(
                $field['key'],
                is_array($value) ? json_encode($value) : $value,
                $field['helper'] ?? null,
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

        BrandingSetting::setValue($field['key'], $value, $field['helper'] ?? null);
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
     * @return array<int, array<string, mixed>>
     */
    private function fields(): array
    {
        return collect(config('settings.groups', []))
            ->flatMap(fn (array $group) => $group['fields'])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $field
     * @return array<string, mixed>
     */
    private function field(array $field): array
    {
        $store = $field['store'] ?? config('settings.store', 'branding');
        $default = config("{$store}.{$field['key']}");
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

        // A secret is never sent to the browser: a stored one is represented by the mask,
        // which the save side reads back as "unchanged".
        if ($field['type'] === 'password') {
            $stored = is_string($value) && trim($value) !== '';

            return [
                'key' => $field['key'],
                'label' => $field['label'],
                'type' => 'password',
                'helper' => $field['helper'] ?? null,
                'purpose' => null,
                'full' => $field['full'] ?? false,
                'presets' => null,
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
            'purpose' => $field['purpose'] ?? null,
            'full' => $field['full'] ?? false,
            // Colour fields may offer a swatch row; hex => label, in order.
            'presets' => $this->presets($field),
            'required' => in_array('required', $field['rules'] ?? [], true),
            'value' => $value,
            'default' => $default,
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

        return \App\Support\ColorPresets::forFrontend();
    }

    /**
     * Same resolution BrandingService uses: absolute URLs and rooted paths pass
     * through, anything else is a path on the public disk.
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

        return Storage::disk('public')->url($path);
    }
}
