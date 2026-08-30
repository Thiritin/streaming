<?php

namespace App\Console\Commands;

use App\Models\BrandingSetting;
use App\Support\Manage\Settings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * Scripted branding, for a deploy that wants to arrive already branded.
 *
 * Branding is not read from the environment: it lives in branding_settings and
 * is edited in /manage > Settings. This command is the same write from a shell,
 * so provisioning can set it once without a second source of truth that would
 * silently stop applying the first time somebody saves the form.
 */
class BrandingSetCommand extends Command
{
    protected $signature = 'branding:set
        {pairs?* : key=value pairs, e.g. primary_color=#0e7490 site_name="My Con"}
        {--list : Show every key with its current value and shipped default}';

    /**
     * Repeater fields take JSON on the command line, e.g.
     *   footer_links=\'[{"label":"Privacy","url":"https://example.org/privacy"}]\'
     */
    private const JSON_TYPES = ['links'];

    protected $description = 'Set branding values (name, copy, links, accent colour) from the command line';

    public function handle(Settings $settings): int
    {
        // groups() has already flattened any cards into `fields`, so this is every
        // field of every pane whatever shape the pane is declared in.
        $fields = collect($settings->groups())
            ->flatMap(fn (array $group) => $group['fields'])
            ->keyBy('key');

        if ($this->option('list') || $this->argument('pairs') === []) {
            $this->table(
                ['Key', 'Current', 'Default'],
                $fields->map(fn (array $field) => [
                    $field['key'],
                    $this->show($field),
                    $this->truncate($field['default']),
                ])->values()->all(),
            );

            return self::SUCCESS;
        }

        $parsed = [];

        foreach ($this->argument('pairs') as $pair) {
            if (! str_contains($pair, '=')) {
                $this->error("Expected key=value, got \"{$pair}\".");

                return self::FAILURE;
            }

            [$key, $value] = explode('=', $pair, 2);
            $key = trim($key);

            if (! $fields->has($key)) {
                $this->error("Unknown branding key \"{$key}\". Run with --list to see them all.");

                return self::FAILURE;
            }

            if (in_array($fields[$key]['type'], self::JSON_TYPES, true)) {
                $decoded = json_decode($value, true);

                if (! is_array($decoded)) {
                    $this->error("\"{$key}\" takes a JSON list, e.g. ".
                        '\'[{"label":"Privacy","url":"https://example.org/privacy"}]\'');

                    return self::FAILURE;
                }

                $value = $decoded;
            }

            $parsed[$key] = $value;
        }

        // The same rules the form applies, so a scripted write cannot store
        // something the panel would have rejected.
        $rules = collect($settings->rules())
            ->only(array_map(fn ($key) => 'values.'.$key, array_keys($parsed)))
            ->all();

        $validator = Validator::make(
            ['values' => $parsed],
            $rules,
            [],
            $settings->attributes(),
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $settings->save($parsed);

        foreach (array_keys($parsed) as $key) {
            $stored = BrandingSetting::where('key', $key)->exists();

            $this->line($stored
                ? "  <info>{$key}</info> set"
                : "  <info>{$key}</info> back to the shipped default");
        }

        return self::SUCCESS;
    }

    /**
     * A field's current value, except for one stored encrypted: a secret is written
     * from here, never read back out of it.
     *
     * @param  array<string, mixed>  $field
     */
    private function show(array $field): string
    {
        if (($field['secure'] ?? false) && ($field['hasValue'] ?? false)) {
            return Settings::MASK_SECRET;
        }

        return $this->truncate($field['value']);
    }

    private function truncate(mixed $value): string
    {
        $value = is_array($value) ? json_encode($value) : (string) $value;

        return mb_strlen($value) > 40 ? mb_substr($value, 0, 39).'…' : $value;
    }
}
