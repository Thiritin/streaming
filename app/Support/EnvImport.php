<?php

namespace App\Support;

use App\Models\BrandingSetting;
use App\Support\Manage\Settings;
use Illuminate\Support\Env;

/**
 * What an installation's `.env` still holds that the settings table should hold instead.
 *
 * Configuration moved out of the environment and into `branding_settings`, read back over
 * the config repository by RuntimeConfig. Most fields kept their `env()` call as the
 * shipped default, so an unmigrated deploy behaves identically - but "mostly" is not
 * something anybody should deploy on, and a variable that lost its reader is silent.
 *
 * So every registry field is classified here by hand rather than by guessing at the
 * config files. A field with no entry is an error, not a default: `settings:import-env`
 * exits non-zero on one, which is what keeps a knob added later from being quietly
 * skipped by the very command that exists to find it.
 *
 * The four answers, in the order they matter:
 *
 *  ENV      the environment still feeds it, so the value can be copied into a row and
 *           the variable dropped afterwards.
 *  MOVED    the variable was renamed, or its config path was, so the old value is now
 *           read differently or not at all. Importing is the fix, and the command says
 *           so loudly.
 *  PANEL    the field never had a variable behind it. Nothing to import.
 *
 * and two that are about the environment rather than about a field:
 *
 *  SEEDED   the feature left config for a table of its own, and a migration copies the
 *           old values across. Nothing here may touch those.
 *  OBSOLETE the variable went with the feature it configured. Nothing reads it.
 */
final class EnvImport
{
    public const ENV = 'env';

    public const MOVED = 'moved';

    public const PANEL = 'panel';

    /**
     * Every field in the registry, and where its value comes from.
     *
     * `vars` are read in order, first one set winning, which mirrors the `?:` chains in
     * the config files. `implied` names variables that do not supply the field but do
     * change what the config file answers for it when they are present - the only one
     * today is the DNS driver, inferred from whether the nsupdate settings are there.
     * Both lists are removed from the environment when the shipped default is computed,
     * or a value that only looks like the default would be skipped.
     *
     * `keep` marks a variable something other than this application still reads: a
     * container, a provisioning script, or the bootstrap that has to answer before there
     * is a database. Those stay in `.env` after the import.
     *
     * @var array<string, array{class: string, vars?: array<int, string>, implied?: array<int, string>, keep?: bool, note?: string}>
     */
    private const FIELDS = [
        // Sign-in
        'auth_required' => ['class' => self::ENV, 'vars' => ['AUTH_REQUIRED']],
        'auth_local' => ['class' => self::PANEL],
        'auth_registration' => ['class' => self::PANEL],
        'auth_oauth2' => ['class' => self::PANEL, 'note' => 'The providers behind it are rows in auth_providers, seeded from OIDC_* by a migration.'],
        'identity_name' => ['class' => self::PANEL],
        'identity_register_url' => ['class' => self::PANEL],
        'identity_logout_url' => ['class' => self::PANEL],
        'login_headline' => ['class' => self::PANEL],
        'login_button_label' => ['class' => self::PANEL],
        'login_body' => ['class' => self::PANEL],

        // Branding
        'convention_name' => ['class' => self::ENV, 'vars' => ['APP_NAME'], 'keep' => true],
        'site_name' => ['class' => self::ENV, 'vars' => ['APP_NAME'], 'keep' => true],
        'primary_color' => ['class' => self::PANEL],
        'logo_path' => ['class' => self::PANEL],
        'favicon_path' => ['class' => self::PANEL],
        'login_background_image' => ['class' => self::PANEL],
        'footer_links' => ['class' => self::PANEL],
        'show_source_link' => ['class' => self::PANEL],

        // Announcement
        'announcement_enabled' => ['class' => self::PANEL],
        'announcement_level' => ['class' => self::PANEL],
        'announcement_title' => ['class' => self::PANEL],
        'announcement_body' => ['class' => self::PANEL],
        'announcement_details' => ['class' => self::PANEL],
        'announcement_link_url' => ['class' => self::PANEL],
        'announcement_link_label' => ['class' => self::PANEL],
        'announcement_dismissible' => ['class' => self::PANEL],

        // Features
        'chat' => ['class' => self::ENV, 'vars' => ['CHAT_ENABLED']],
        'emotes' => ['class' => self::PANEL],
        'boops' => ['class' => self::PANEL],
        'comments' => ['class' => self::PANEL],
        'announcement' => ['class' => self::PANEL],
        'feedback' => ['class' => self::PANEL],
        'screens' => ['class' => self::PANEL],
        'telegram' => ['class' => self::PANEL],

        // Chat
        'chat_max_tries' => ['class' => self::ENV, 'vars' => ['CHAT_MAX_TRIES']],
        'chat_rate_decay' => ['class' => self::ENV, 'vars' => ['CHAT_RATE_DECAY']],
        'chat_slow_mode_seconds' => ['class' => self::ENV, 'vars' => ['CHAT_SLOW_MODE_SECONDS']],
        'chat_max_message_length' => ['class' => self::ENV, 'vars' => ['CHAT_MAX_MESSAGE_LENGTH']],
        'chat_allowed_domains' => [
            'class' => self::ENV,
            'vars' => ['CHAT_ALLOWED_DOMAINS'],
            'note' => 'The config value is a string now rather than an exploded list; the sanitiser reads either.',
        ],

        // Pretalx
        'pretalx_url' => ['class' => self::PANEL],
        'pretalx_event' => ['class' => self::PANEL],
        'pretalx_token' => ['class' => self::PANEL],

        // Streaming
        'image_ffmpeg_hls' => ['class' => self::ENV, 'vars' => ['STREAM_IMAGE_FFMPEG_HLS']],
        'image_archive_uploader' => ['class' => self::ENV, 'vars' => ['STREAM_IMAGE_ARCHIVE_UPLOADER']],
        'server_metrics_retention_days' => ['class' => self::ENV, 'vars' => ['SERVER_METRICS_RETENTION_DAYS']],

        // Servers and DNS
        'cloud_driver' => ['class' => self::ENV, 'vars' => ['CLOUD_DRIVER'], 'keep' => true],
        'hetzner_token' => ['class' => self::ENV, 'vars' => ['HETZNER_TOKEN'], 'keep' => true],
        'hetzner_location' => ['class' => self::ENV, 'vars' => ['HETZNER_LOCATION']],
        'hetzner_image' => ['class' => self::ENV, 'vars' => ['HETZNER_IMAGE']],
        'hetzner_ssh_key_name' => ['class' => self::ENV, 'vars' => ['HETZNER_SSH_KEY']],
        'hetzner_network_name' => ['class' => self::ENV, 'vars' => ['HETZNER_NETWORK']],
        'server_default_origin' => ['class' => self::ENV, 'vars' => ['HETZNER_ORIGIN_TYPE']],
        'server_default_edge' => ['class' => self::ENV, 'vars' => ['HETZNER_EDGE_TYPE']],
        'dns_driver' => [
            'class' => self::ENV,
            'vars' => ['DNS_DRIVER'],
            'implied' => ['DNS_SERVER', 'DNS_ZONE', 'DNS_KEY_SECRET'],
            'keep' => true,
            'note' => 'Inferred from the nsupdate variables when DNS_DRIVER is unset, so importing it is what survives their removal.',
        ],
        'dns_zone' => ['class' => self::ENV, 'vars' => ['DNS_ZONE'], 'keep' => true],
        'dns_ttl' => ['class' => self::ENV, 'vars' => ['DNS_TTL'], 'keep' => true],
        'dns_server' => ['class' => self::ENV, 'vars' => ['DNS_SERVER'], 'keep' => true],
        'dns_key_name' => ['class' => self::ENV, 'vars' => ['DNS_KEY_NAME'], 'keep' => true],
        'dns_key_algorithm' => ['class' => self::ENV, 'vars' => ['DNS_KEY_ALGORITHM'], 'keep' => true],
        'dns_key_secret' => ['class' => self::ENV, 'vars' => ['DNS_KEY_SECRET'], 'keep' => true],
        'dns_cloudflare_token' => ['class' => self::ENV, 'vars' => ['CLOUDFLARE_DNS_TOKEN'], 'keep' => true],
        'dns_cloudflare_zone_id' => ['class' => self::ENV, 'vars' => ['CLOUDFLARE_ZONE_ID'], 'keep' => true],
        'dns_hetzner_token' => ['class' => self::ENV, 'vars' => ['HETZNER_DNS_TOKEN'], 'keep' => true],
        'dns_hetzner_zone_id' => ['class' => self::ENV, 'vars' => ['HETZNER_DNS_ZONE_ID'], 'keep' => true],

        // Archive storage
        'archive_disk' => ['class' => self::ENV, 'vars' => ['ARCHIVE_DISK']],
        'archive_quota_bytes' => ['class' => self::ENV, 'vars' => ['ARCHIVE_QUOTA_BYTES']],
        'archive_s3_endpoint' => ['class' => self::ENV, 'vars' => ['DVR_AWS_ENDPOINT'], 'keep' => true],
        'archive_s3_bucket' => ['class' => self::ENV, 'vars' => ['DVR_AWS_BUCKET'], 'keep' => true],
        'archive_s3_region' => ['class' => self::ENV, 'vars' => ['DVR_AWS_DEFAULT_REGION'], 'keep' => true],
        'archive_s3_key' => ['class' => self::ENV, 'vars' => ['DVR_AWS_ACCESS_KEY_ID'], 'keep' => true],
        'archive_s3_secret' => ['class' => self::ENV, 'vars' => ['DVR_AWS_SECRET_ACCESS_KEY'], 'keep' => true],
        'archive_s3_url' => ['class' => self::ENV, 'vars' => ['DVR_AWS_URL'], 'keep' => true],
        'archive_s3_path_style' => ['class' => self::ENV, 'vars' => ['DVR_AWS_USE_PATH_STYLE_ENDPOINT'], 'keep' => true],
        'archive_url_mode' => ['class' => self::ENV, 'vars' => ['ARCHIVE_URL_MODE']],
        'archive_url_ttl' => ['class' => self::ENV, 'vars' => ['ARCHIVE_URL_TTL']],
        'archive_source_in_master' => ['class' => self::ENV, 'vars' => ['ARCHIVE_SOURCE_IN_MASTER']],

        // Tokens and keys
        'hls_viewer_secret' => ['class' => self::ENV, 'vars' => ['HLS_VIEWER_SECRET'], 'keep' => true],
        'hls_embed_secret' => ['class' => self::ENV, 'vars' => ['HLS_EMBED_SECRET'], 'keep' => true],
        'hls_token_ttl' => ['class' => self::ENV, 'vars' => ['HLS_TOKEN_TTL']],
        'hls_token_leeway' => ['class' => self::ENV, 'vars' => ['HLS_TOKEN_LEEWAY'], 'keep' => true],
        'hls_token_bucket' => ['class' => self::ENV, 'vars' => ['HLS_TOKEN_BUCKET']],
        'hls_token_refresh_margin' => ['class' => self::ENV, 'vars' => ['HLS_TOKEN_REFRESH_MARGIN']],
        'system_streamkey' => [
            'class' => self::MOVED,
            'vars' => ['STREAM_SYSTEM_STREAMKEY', 'STREAM_KEY'],
            'keep' => true,
            'note' => 'The SRS play and stop hooks read config(stream.system_streamkey) now; they read config(app.stream_key), which was STREAM_KEY, before. With both variables set and holding different keys, whatever pushes with STREAM_KEY is refused after the deploy.',
        ],
        'recording_api_key' => ['class' => self::ENV, 'vars' => ['RECORDING_API_KEY']],
        'control_key' => ['class' => self::PANEL, 'note' => 'COMPANION_API_KEY is copied into this row by a migration.'],
        'import_key' => ['class' => self::PANEL],

        // Notifications
        'telegram_bot_token' => ['class' => self::PANEL],
        'telegram_bot_username' => ['class' => self::PANEL],
        'telegram_show_lead_minutes' => ['class' => self::PANEL],
        'notification_delay_hours' => ['class' => self::PANEL],
    ];

    /**
     * Variables a migration reads once and copies somewhere the panel owns. Nothing here
     * may be imported: the migration is the only writer, and a second one could only
     * disagree with it.
     *
     * @var array<int, array{vars: array<int, string>, what: string, by: string}>
     */
    private const SEEDED = [
        [
            'vars' => ['OIDC_URL', 'OIDC_CLIENT_ID', 'OIDC_SECRET'],
            'what' => 'the identity provider, now a row in auth_providers',
            'by' => '2026_08_30_100200_seed_legacy_auth_provider',
        ],
        [
            'vars' => ['OIDC_GROUP_ROLE_MAP'],
            'what' => "each role's external id",
            'by' => '2026_08_03_010000_replace_assigned_at_login_with_external_id_on_roles',
        ],
        [
            'vars' => ['COMPANION_API_KEY'],
            'what' => 'the control key settings row',
            'by' => '2026_08_18_190000_move_control_key_into_settings',
        ],
    ];

    /**
     * Variables whose feature is gone. Nothing reads them; they are noise in a `.env`
     * and, in one case, noise that used to weaken an authorisation check.
     *
     * @var array<string, string>
     */
    private const OBSOLETE = [
        'RTMP_FORWARD' => 'RTMP forwarding, removed',
        'RTMP_VRCHAT_URL' => 'RTMP forwarding, removed',
        'LOCAL_STREAMING_IPV4_SUBNET' => 'the venue network override, removed',
        'LOCAL_STREAMING_IPV6_SUBNET' => 'the venue network override, removed',
        'LOCAL_STREAMING_HOSTNAME' => 'the venue network override, removed',
        'SRS_USERNAME' => 'SRS console credentials, removed',
        'SRS_PASSWORD' => 'SRS console credentials, removed',
        'SRS_ORIGIN' => 'SRS console credentials, removed',
        'ORIGIN_IP' => 'the SRS hook bypass, removed - the hook route is behind a shared secret now',
        'STREAM_RTMP_HOST' => 'unread RTMP configuration, removed',
        'STREAM_RTMP_PORT' => 'unread RTMP configuration, removed',
        'STREAM_VALIDATE_SESSION_IP' => 'unread session configuration, removed',
        'STREAM_SESSION_TIMEOUT' => 'unread session configuration, removed',
        'STREAM_HLS_TRACKER_API_KEY' => 'unread tracker configuration, removed',
        'SIGNAGE_STREAMKEY' => 'nothing reads it; displays mint an embed token per source',
        'VITE_PUSHER_APP_KEY' => 'the frontend reads the VITE_REVERB_* pair',
        'VITE_PUSHER_HOST' => 'the frontend reads the VITE_REVERB_* pair',
        'VITE_PUSHER_PORT' => 'the frontend reads the VITE_REVERB_* pair',
        'VITE_PUSHER_SCHEME' => 'the frontend reads the VITE_REVERB_* pair',
        'VITE_PUSHER_APP_CLUSTER' => 'the frontend reads the VITE_REVERB_* pair',
    ];

    /**
     * Settings keys that left the registry with the fields they belonged to. A row for
     * one of these is read by nothing and can be deleted.
     *
     * @var array<int, string>
     */
    private const ORPHANED = [
        'login_eyebrow',
        'login_tagline',
        'login_background_video',
    ];

    /**
     * One row per registry field: what it would do and why.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function plan(): array
    {
        $saved = BrandingSetting::query()->pluck('key')->flip();

        $rows = [];

        foreach (self::registry() as $key => $field) {
            $rows[] = self::row($key, $field, $saved->has($key));
        }

        return $rows;
    }

    /**
     * Registry fields with no entry in the table above. A non-empty answer is what the
     * command refuses on: an unclassified field is a guess waiting to be made.
     *
     * @return array<int, string>
     */
    public static function unclassified(): array
    {
        return array_values(array_diff(array_keys(self::registry()), array_keys(self::FIELDS)));
    }

    /**
     * Entries in the table above for fields the registry no longer has.
     *
     * @return array<int, string>
     */
    public static function stale(): array
    {
        return array_values(array_diff(array_keys(self::FIELDS), array_keys(self::registry())));
    }

    /**
     * Every variable this class knows about, in any of its four answers. The deploy note
     * is written from it, and a test can clear the lot to get a known environment.
     *
     * @return array<int, string>
     */
    public static function variables(): array
    {
        $vars = array_keys(self::OBSOLETE);

        foreach (self::FIELDS as $entry) {
            $vars = [...$vars, ...($entry['vars'] ?? []), ...($entry['implied'] ?? [])];
        }

        foreach (self::SEEDED as $group) {
            $vars = [...$vars, ...$group['vars']];
        }

        return array_values(array_unique($vars));
    }

    /**
     * The seeded groups whose variables this environment actually sets.
     *
     * @return array<int, array{vars: array<int, string>, what: string, by: string}>
     */
    public static function seeded(): array
    {
        $found = [];

        foreach (self::SEEDED as $group) {
            $set = array_values(array_filter($group['vars'], fn (string $var) => self::isSet($var)));

            if ($set !== []) {
                $found[] = [...$group, 'vars' => $set];
            }
        }

        return $found;
    }

    /**
     * Obsolete variables this environment still sets.
     *
     * @return array<string, string>
     */
    public static function obsolete(): array
    {
        return array_filter(
            self::OBSOLETE,
            fn (string $var) => self::isSet($var),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * Saved rows for keys nothing reads any more.
     *
     * @return array<int, string>
     */
    public static function orphans(): array
    {
        return BrandingSetting::query()
            ->whereIn('key', self::ORPHANED)
            ->pluck('key')
            ->all();
    }

    /**
     * Variables the import makes redundant, so the deploy note can list them.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, string>
     */
    public static function retirable(array $rows): array
    {
        $vars = [];

        foreach ($rows as $row) {
            if ($row['action'] !== 'import' || ($row['keep'] ?? false)) {
                continue;
            }

            $vars[] = $row['var'];
        }

        return array_values(array_unique($vars));
    }

    /**
     * Write one planned row. Deliberately not through Settings::save(), which deletes a
     * value equal to the field's default - and while the variable is still in `.env`
     * that default *is* the value being imported, so every write would delete itself.
     *
     * @param  array<string, mixed>  $row
     */
    public static function write(array $row): void
    {
        BrandingSetting::setValue(
            $row['key'],
            $row['stored'],
            'Imported from '.$row['var'].'.',
            $row['secure'],
        );
    }

    /**
     * @param  array<string, mixed>  $field
     * @return array<string, mixed>
     */
    private static function row(string $key, array $field, bool $saved): array
    {
        $entry = self::FIELDS[$key] ?? null;
        $path = Settings::configPath($field);

        $row = [
            'key' => $key,
            'path' => $path,
            'class' => $entry['class'] ?? null,
            'vars' => $entry['vars'] ?? [],
            'var' => null,
            'keep' => $entry['keep'] ?? false,
            'note' => $entry['note'] ?? null,
            'secure' => Settings::isSecure($field),
            'masked' => Settings::isSecure($field) || in_array($field['type'] ?? null, ['password', 'secret'], true),
            'stored' => null,
            'action' => 'skip',
            'reason' => 'unclassified',
        ];

        if ($entry === null) {
            return $row;
        }

        if ($saved) {
            return [...$row, 'reason' => 'saved'];
        }

        if ($entry['class'] === self::PANEL) {
            return [...$row, 'reason' => 'panel'];
        }

        $var = self::supplier($entry['vars']);

        if ($var === null) {
            return [...$row, 'reason' => 'unset'];
        }

        $row['var'] = $var;
        $row['stored'] = self::stored($field, RuntimeConfig::shipped($path));

        $shipped = self::stored($field, self::withoutVars(
            [...$entry['vars'], ...($entry['implied'] ?? [])],
            fn () => self::pathValue($path),
        ));

        if ($row['stored'] === null || $row['stored'] === '') {
            return [...$row, 'reason' => 'empty'];
        }

        if ($row['stored'] === $shipped) {
            return [...$row, 'reason' => 'default'];
        }

        return [...$row, 'action' => 'import', 'reason' => 'value'];
    }

    /**
     * The registry, keyed by field key, cards flattened.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function registry(): array
    {
        $fields = [];

        foreach (config('settings.groups', []) as $group) {
            foreach (Settings::declaredFields($group) as $field) {
                $fields[$field['key']] = $field;
            }
        }

        return $fields;
    }

    /**
     * The first of a field's variables this environment sets, or null for none.
     *
     * @param  array<int, string>  $vars
     */
    private static function supplier(array $vars): ?string
    {
        foreach ($vars as $var) {
            if (self::isSet($var)) {
                return $var;
            }
        }

        return null;
    }

    private static function isSet(string $var): bool
    {
        $value = Env::get($var);

        return $value !== null && $value !== '';
    }

    /**
     * What the config file answers for a path with these variables out of the way.
     *
     * The file is re-read rather than the default being written down a second time here:
     * a copy of `env('HLS_TOKEN_TTL', 900)`'s fallback in this class is a number that
     * goes stale the first time somebody changes the real one, and the whole point of
     * this comparison is to tell a value apart from its default exactly.
     *
     * The variables are taken out of every place env() looks and put back in a finally,
     * and nothing between the two reads config() - only the file itself.
     *
     * @param  array<int, string>  $vars
     */
    private static function withoutVars(array $vars, callable $read): mixed
    {
        $saved = [];

        foreach ($vars as $var) {
            $saved[$var] = [$_ENV[$var] ?? null, $_SERVER[$var] ?? null, getenv($var)];

            unset($_ENV[$var], $_SERVER[$var]);
            putenv($var);
        }

        try {
            return $read();
        } finally {
            foreach ($saved as $var => [$env, $server, $put]) {
                if ($env !== null) {
                    $_ENV[$var] = $env;
                }

                if ($server !== null) {
                    $_SERVER[$var] = $server;
                }

                if ($put !== false) {
                    putenv("{$var}={$put}");
                }
            }
        }
    }

    /**
     * A config path read straight out of its file, so the overlay and the cached
     * repository are both out of the picture.
     */
    private static function pathValue(string $path): mixed
    {
        $file = strtok($path, '.');
        $rest = substr($path, strlen($file) + 1);

        $config = require config_path("{$file}.php");

        return $rest === '' ? $config : data_get($config, $rest);
    }

    /**
     * A config value in the terms the settings table holds it: strings, with a toggle
     * as '1' or '0' and a repeater as JSON.
     *
     * @param  array<string, mixed>  $field
     */
    private static function stored(array $field, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (Settings::castOf($field) === 'bool') {
            return Settings::toBool($value) ? '1' : '0';
        }

        if (is_array($value)) {
            return json_encode($value);
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return (string) $value;
    }
}
