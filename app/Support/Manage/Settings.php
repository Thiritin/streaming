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
        }

        return $attributes;
    }

    /**
     * Save the posted values, ignoring anything not declared in the registry.
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

            BrandingSetting::setValue(
                $field['key'],
                is_string($value) ? trim($value) : $value,
                $field['helper'] ?? null,
            );
        }
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

        $presets = [];

        foreach ($field['presets'] as $hex => $label) {
            $presets[] = ['hex' => $hex, 'label' => $label];
        }

        return $presets;
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
