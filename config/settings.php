<?php

use App\Support\Announcement;
use App\Support\ColorPresets;

return [

    /*
    |--------------------------------------------------------------------------
    | Editable settings
    |--------------------------------------------------------------------------
    |
    | The /manage settings screens are generated from this file. Each group becomes a
    | pane of its own with its own URL and its own entry in the settings menu, each
    | field one row of that pane, and the validation rules here are the rules that
    | pane's update request applies, so adding a knob is one entry rather than a form,
    | a request class and a page change. The group order here is the order of the menu.
    |
    | `icon` names an entry in resources/js/Components/Manage/ManageIcon.vue. `blurb`
    | labels the pane's menu entry and stays to a few words; `description` is the
    | longer line the pane itself opens with.
    |
    | `store` is the config namespace a field falls back to when nothing has been
    | saved, and `key` is the flat key written to the settings table. Both together
    | mean a field can be moved to another config file without touching saved data.
    |
    | A saved value is laid back over the config repository at boot by
    | App\Support\RuntimeConfig, so every call site keeps reading config() and the
    | table wins. `config` names the dotted path a field overrides when that is not
    | its own `{store}.{key}`, for example services.oidc.client_id. `cast` says how
    | the stored string becomes a config value (int, bool, float, string, array);
    | toggles and link repeaters already answer for themselves. `secure` stores the
    | value encrypted at rest, for anything an operator would not want read back off
    | the table.
    |
    | The overlay only reaches a call site that reads config() when it is called. A
    | value read during a service provider's boot, or otherwise captured into something
    | built once, is fixed for the life of the process: under Octane and on a queue
    | worker the re-apply happens after that boot, so the field will look like it does
    | nothing. Making such a key settable means moving its read to call time first.
    | Anything resolved and memoised by a manager needs dropping as well, which is why
    | RuntimeConfig forgets the disks it overrides.
    |
    | The overlay is re-applied between jobs and never inside one, so a job already
    | running finishes on the values it started with. A change is a change for the next
    | job, not for the one in flight, which is what a long-running import or archive
    | scan wants anyway.
    |
    | Types: text, textarea, url, color, image, video, toggle, select, links,
    | password, secret. Uploads name a purpose from config/manage.php, which owns the
    | disk, directory and size limits. `password` is write-only; `secret` is a value
    | this installation hands out, so it can be read back, copied and generated. A
    | `select` names its choices as value => label in `options`.
    |
    | A group may carry a `note`: one line of copy with a link beside it, saving
    | nothing. `url_config` names the config key the link comes from, and a note whose
    | key resolves to an empty value is dropped rather than shown dead.
    |
    | A group may declare `cards` instead of a flat `fields`: a list of collapsible
    | blocks, each with its own `label`, its own subset of the fields and, where it
    | needs one, its own `note` - a note is per card as well as per group, or merging
    | two panes that each carried one would silently drop the second. Settings::fields()
    | flattens them, so rules(), attributes() and save() never learn cards exist and a
    | group that declares none renders exactly as it did before. `render` on a card
    | names a body the page draws itself, for a card whose contents are rows rather
    | than registry fields.
    |
    | `visible_when` hides a field until another field on the same pane holds one of
    | the listed values: ['field' => 'auth_local', 'is' => [true]]. It is copy and
    | layout only - a hidden field still posts, and a `required` rule on one becomes
    | conditional on the same answer so a pane cannot fail on a control nobody saw.
    |
    | `inert_when` is the same shape one level up, for a card whose contents another
    | field has made moot: the card stays on screen with its saved values readable and
    | every control in it disabled, plus the one line `description` says why. Shown
    | rather than hidden, because a card that vanishes reads as a card that was never
    | configured.
    |
    */

    'groups' => [

        [
            'key' => 'identity',
            'section' => 'Site',
            'icon' => 'key',
            'label' => 'Sign-in',
            'description' => 'How people get in, and what they read on the way.',
            'cards' => [
                /*
                 * One card, because "how does somebody get in" is one question. Guest
                 * access is not a way in - it is permission to browse without one - but
                 * it is the first thing an operator decides here, so it leads.
                 */
                [
                    'key' => 'methods',
                    'label' => 'Sign-in Methods',
                    'render' => 'providers_link',
                    'fields' => [
                        [
                            'key' => 'auth_required',
                            'label' => 'Guest Access',
                            'type' => 'toggle',
                            'config' => 'auth.required',
                            // Shown as its own opposite: the row asks whether a guest may
                            // watch, the column stores whether sign-in is required. Only
                            // the page inverts; the table and every reader are unchanged.
                            'invert' => true,
                            'helper' => 'Enables viewing stream content without an account.',
                            'rules' => ['boolean'],
                        ],
                        [
                            'key' => 'auth_local',
                            'label' => 'Password',
                            'type' => 'toggle',
                            'config' => 'auth.modes.local',
                            'helper' => 'Allows logging in with usernames and passwords.',
                            'rules' => ['boolean'],
                        ],
                        [
                            'key' => 'auth_registration',
                            'label' => 'Registration',
                            'type' => 'toggle',
                            'config' => 'auth.modes.registration',
                            // Registration is an option on password accounts, not a mode
                            // of its own, so it is indented under them and not offered
                            // while they are off.
                            'visible_when' => ['field' => 'auth_local', 'is' => [true]],
                            'indent' => true,
                            'helper' => 'Allows anyone to create a password account.',
                            'rules' => ['boolean'],
                        ],
                        [
                            'key' => 'auth_oauth2',
                            'label' => 'OAuth2',
                            'type' => 'toggle',
                            'config' => 'auth.modes.oauth2',
                            'helper' => 'Allows login via configured OAuth2 providers.',
                            'rules' => ['boolean'],
                        ],
                    ],
                ],
                [
                    'key' => 'provider_pages',
                    'label' => 'Provider pages',
                    'fields' => [
                        [
                            'key' => 'identity_name',
                            'label' => 'Identity provider name',
                            'type' => 'text',
                            'rules' => ['required', 'string', 'max:255'],
                        ],
                        [
                            'key' => 'identity_register_url',
                            'label' => 'Register URL',
                            'type' => 'url',
                            'rules' => ['nullable', 'url', 'max:2048'],
                        ],
                        [
                            'key' => 'identity_logout_url',
                            'label' => 'Logout URL',
                            'type' => 'url',
                            'rules' => ['nullable', 'url', 'max:2048'],
                        ],
                    ],
                ],
                [
                    'key' => 'login',
                    'label' => 'Login screen',
                    'fields' => [
                        [
                            'key' => 'login_headline',
                            'label' => 'Headline',
                            'type' => 'text',
                            'rules' => ['required', 'string', 'max:255'],
                        ],
                        [
                            'key' => 'login_button_label',
                            'label' => 'Button label',
                            'type' => 'text',
                            'rules' => ['required', 'string', 'max:255'],
                        ],
                        [
                            'key' => 'login_body',
                            'label' => 'Intro paragraph',
                            'type' => 'textarea',
                            'rules' => ['nullable', 'string', 'max:2000'],
                            'full' => true,
                        ],
                    ],
                ],
            ],
        ],

        [
            'key' => 'branding',
            'section' => 'Site',
            'icon' => 'paintbrush',
            'label' => 'Branding',
            'description' => 'What this installation is called, and what it looks like. Uploads land on the public disk.',
            'cards' => [
                [
                    'key' => 'site',
                    'label' => 'Site',
                    'fields' => [
                        [
                            'key' => 'convention_name',
                            'label' => 'Convention name',
                            'type' => 'text',
                            'rules' => ['required', 'string', 'max:255'],
                        ],
                        [
                            'key' => 'site_name',
                            'label' => 'Site name',
                            'type' => 'text',
                            'rules' => ['required', 'string', 'max:255'],
                        ],
                    ],
                ],
                [
                    'key' => 'brand',
                    'label' => 'Logo and colours',
                    'fields' => [
                        [
                            'key' => 'primary_color',
                            'label' => 'Accent colour',
                            'type' => 'color',
                            'rules' => ['nullable', 'string', 'regex:/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'],
                            'presets' => ColorPresets::PRESETS,
                        ],
                        [
                            'key' => 'logo_path',
                            'label' => 'Logo',
                            'type' => 'image',
                            'purpose' => 'branding_logo',
                            'rules' => ['nullable', 'string', 'max:2048'],
                        ],
                        [
                            'key' => 'favicon_path',
                            'label' => 'Tab icon',
                            'type' => 'image',
                            'purpose' => 'branding_favicon',
                            'accept' => 'image/png,image/svg+xml,image/webp,image/x-icon,image/vnd.microsoft.icon',
                            'preview_fit' => 'contain',
                            'rules' => ['nullable', 'string', 'max:2048'],
                        ],
                    ],
                ],
                [
                    'key' => 'login_background',
                    'label' => 'Login background',
                    'fields' => [
                        [
                            'key' => 'login_background_image',
                            'label' => 'Login background image',
                            'type' => 'image',
                            'purpose' => 'branding_login_image',
                            'rules' => ['nullable', 'string', 'max:2048'],
                        ],
                    ],
                ],
                [
                    'key' => 'footer',
                    'label' => 'Footer',
                    'fields' => [
                        [
                            'key' => 'footer_links',
                            'label' => 'Links',
                            // A repeater of {label, url} rows, stored as JSON. `itemRules`
                            // are expanded onto values.footer_links.* by Settings::rules().
                            'type' => 'links',
                            'full' => true,
                            'rules' => ['nullable', 'array', 'max:12'],
                            'itemRules' => [
                                'label' => ['required', 'string', 'max:40'],
                                'url' => ['required', 'url', 'max:2048'],
                            ],
                        ],
                        [
                            'key' => 'show_source_link',
                            'label' => 'Source and licence credit',
                            'type' => 'toggle',
                            'helper' => 'Show "Open source, GPL-3.0" in the footer, linking to the project on GitHub.',
                            'rules' => ['boolean'],
                        ],
                    ],
                ],
            ],
        ],

        [
            'key' => 'announcement',
            'section' => 'Site',
            'icon' => 'triangle-alert',
            'label' => 'Announcement',
            'description' => 'One banner across the top of the public site, for whatever everybody watching needs to know right now. Write the short version on the banner and the rest below it, which becomes a page of its own. Clearing the banner text takes the whole thing down whatever the switch says.',
            'fields' => [
                [
                    'key' => 'announcement_enabled',
                    'label' => 'Show the banner',
                    'type' => 'toggle',
                    'store' => 'announcement',
                    'rules' => ['boolean'],
                ],
                [
                    'key' => 'announcement_level',
                    'label' => 'Level',
                    'type' => 'select',
                    'store' => 'announcement',
                    'options' => Announcement::LEVELS,
                    'helper' => 'Colour only. It gates nothing.',
                    'rules' => ['nullable', 'string', 'in:'.implode(',', array_keys(Announcement::LEVELS))],
                ],
                [
                    'key' => 'announcement_title',
                    'label' => 'Title',
                    'type' => 'text',
                    'store' => 'announcement',
                    'full' => true,
                    'rules' => ['nullable', 'string', 'max:120'],
                ],
                [
                    'key' => 'announcement_body',
                    'label' => 'Text',
                    'type' => 'textarea',
                    'store' => 'announcement',
                    'full' => true,
                    'helper' => 'Markdown. Links, bold and italics are kept; raw HTML is stripped. Empty means no banner.',
                    'rules' => ['nullable', 'string', 'max:1000'],
                ],
                [
                    'key' => 'announcement_details',
                    'label' => 'Full announcement',
                    'type' => 'textarea',
                    'store' => 'announcement',
                    'rows' => 12,
                    'full' => true,
                    'helper' => 'Markdown. Anything longer than the banner goes here, and the banner links to it as a page of its own at /announcement. Empty means no page and no link.',
                    'rules' => ['nullable', 'string', 'max:20000'],
                ],
                [
                    'key' => 'announcement_link_url',
                    'label' => 'Link somewhere else',
                    'type' => 'text',
                    'store' => 'announcement',
                    'helper' => 'Only if the detail already lives elsewhere. A full address, or a path on this site. Set, it wins over the announcement page above.',
                    'rules' => ['nullable', 'string', 'max:2048', 'regex:/^(https?:\/\/|\/)/'],
                ],
                [
                    'key' => 'announcement_link_label',
                    'label' => 'Link label',
                    'type' => 'text',
                    'store' => 'announcement',
                    'rules' => ['nullable', 'string', 'max:40'],
                ],
                [
                    'key' => 'announcement_dismissible',
                    'label' => 'Let viewers close it',
                    'type' => 'toggle',
                    'store' => 'announcement',
                    'full' => true,
                    'helper' => 'A closed banner stays closed in that browser until you edit the announcement, which brings it back for everyone.',
                    'rules' => ['boolean'],
                ],
            ],
        ],

        [
            'key' => 'features',
            'section' => 'Site',
            'icon' => 'sliders-horizontal',
            'label' => 'Features',
            'description' => 'Parts of the site an installation can switch off. Turning one off hides it and closes its endpoints, so nothing can be reached by hand either.',
            'fields' => [
                [
                    'key' => 'chat',
                    'label' => 'Chat',
                    'type' => 'toggle',
                    'store' => 'features',
                    'helper' => 'Off hides the chat panel and pop-out everywhere and answers 404 on every chat route. Streams keep playing.',
                    'rules' => ['boolean'],
                ],
                [
                    'key' => 'emotes',
                    'label' => 'Emotes',
                    'type' => 'toggle',
                    'store' => 'features',
                    'helper' => 'Off removes the picker, the autocomplete and inline emotes in messages. Implied off when chat is off.',
                    'rules' => ['boolean'],
                ],
                [
                    'key' => 'boops',
                    'label' => 'Boops',
                    'type' => 'toggle',
                    'store' => 'features',
                    'helper' => 'The paw under the player and its shared counter. Off hides the button and stops accepting boops.',
                    'rules' => ['boolean'],
                ],
                [
                    'key' => 'comments',
                    'label' => 'Comments',
                    'type' => 'toggle',
                    'store' => 'features',
                    'helper' => 'Comments and replies under a recording in the archive. Off hides the section and closes the endpoints without deleting anything already posted.',
                    'rules' => ['boolean'],
                ],
                [
                    'key' => 'announcement',
                    'label' => 'Announcements',
                    'type' => 'toggle',
                    'store' => 'features',
                    'helper' => 'The banner on the front page and the page behind it. Off hides both and answers 404 on /announcement, whatever the Announcement pane says.',
                    'rules' => ['boolean'],
                ],
                [
                    'key' => 'feedback',
                    'label' => 'Feedback',
                    'type' => 'toggle',
                    'store' => 'features',
                    'helper' => 'The report button for viewers and the Feedback module in this panel. Off closes the endpoint and drops the module.',
                    'rules' => ['boolean'],
                ],
                [
                    'key' => 'screens',
                    'label' => 'Screens',
                    'type' => 'toggle',
                    'store' => 'features',
                    'helper' => 'Unattended displays: /d, the display hub, and the Display Keys and Screens modules here. Off answers 404 on all of them.',
                    'rules' => ['boolean'],
                ],
                [
                    'key' => 'telegram',
                    'label' => 'Telegram bot',
                    'type' => 'toggle',
                    'store' => 'features',
                    'helper' => 'Notifications into linked chats and the buttons in them. Off stops every message and closes the webhook without unlinking anything.',
                    'rules' => ['boolean'],
                ],
            ],
        ],

        [
            'key' => 'chat',
            'section' => 'Site',
            'icon' => 'message-square',
            'label' => 'Chat',
            'description' => 'The limits every chat room runs under. Whether chat exists at all is in Features, and slow mode can be overridden per source from the mod tools.',
            'fields' => [
                [
                    'key' => 'chat_max_tries',
                    'label' => 'Messages per rate window',
                    'type' => 'text',
                    'store' => 'chat',
                    'config' => 'chat.default.maxTries',
                    'cast' => 'int',
                    'rules' => ['nullable', 'integer', 'min:1', 'max:100'],
                ],
                [
                    'key' => 'chat_rate_decay',
                    'label' => 'Rate window (seconds)',
                    'type' => 'text',
                    'store' => 'chat',
                    'config' => 'chat.default.rateDecay',
                    'cast' => 'int',
                    'rules' => ['nullable', 'integer', 'min:1', 'max:3600'],
                ],
                [
                    'key' => 'chat_slow_mode_seconds',
                    'label' => 'Slow mode gap (seconds)',
                    'type' => 'text',
                    'store' => 'chat',
                    'config' => 'chat.default.slowModeSeconds',
                    'cast' => 'int',
                    'rules' => ['nullable', 'integer', 'min:0', 'max:3600'],
                ],
                [
                    'key' => 'chat_max_message_length',
                    'label' => 'Message length limit (characters)',
                    'type' => 'text',
                    'store' => 'chat',
                    'config' => 'chat.default.maxMessageLength',
                    'cast' => 'int',
                    'rules' => ['nullable', 'integer', 'min:1', 'max:2000'],
                ],
                [
                    'key' => 'chat_allowed_domains',
                    'label' => 'Allowed link domains',
                    'type' => 'textarea',
                    'store' => 'chat',
                    'config' => 'chat.allowed_domains',
                    'full' => true,
                    'helper' => 'One per line. Empty strips every link.',
                    'rules' => ['nullable', 'string', 'max:2000'],
                ],
            ],
        ],

        [
            'key' => 'pretalx',
            'section' => 'Programme',
            'icon' => 'calendar',
            'label' => 'Pretalx',
            'description' => 'Where the programme comes from. With these set, /manage > Shows can import sessions from the published schedule.',
            'fields' => [
                [
                    'key' => 'pretalx_url',
                    'label' => 'Instance URL',
                    'type' => 'url',
                    'store' => 'pretalx',
                    'rules' => ['nullable', 'url', 'max:2048'],
                ],
                [
                    'key' => 'pretalx_event',
                    'label' => 'Event slug',
                    'type' => 'text',
                    'store' => 'pretalx',
                    'rules' => ['nullable', 'string', 'max:255'],
                ],
                [
                    'key' => 'pretalx_token',
                    'label' => 'API token',
                    'type' => 'password',
                    'store' => 'pretalx',
                    'full' => true,
                    'helper' => 'Only needed while the schedule is unpublished or the event is private.',
                    'rules' => ['nullable', 'string', 'max:255'],
                ],
            ],
        ],

        [
            'key' => 'streaming',
            'section' => 'System',
            'icon' => 'radio-tower',
            'label' => 'Streaming',
            'description' => 'What the generated provisioning scripts are built from.',
            'fields' => [
                [
                    'key' => 'image_ffmpeg_hls',
                    'label' => 'Transcoder image',
                    'type' => 'text',
                    'store' => 'stream',
                    'config' => 'stream.images.ffmpeg_hls',
                    'helper' => 'Fully qualified, registry included.',
                    'rules' => ['nullable', 'string', 'max:255'],
                ],
                [
                    'key' => 'image_archive_uploader',
                    'label' => 'Archive uploader image',
                    'type' => 'text',
                    'store' => 'stream',
                    'config' => 'stream.images.archive_uploader',
                    'rules' => ['nullable', 'string', 'max:255'],
                ],
                [
                    'key' => 'server_metrics_retention_days',
                    'label' => 'Keep server metrics for (days)',
                    'type' => 'text',
                    'store' => 'stream',
                    'config' => 'stream.server.metrics_retention_days',
                    'cast' => 'int',
                    'rules' => ['nullable', 'integer', 'min:1', 'max:365'],
                ],
            ],
        ],

        [
            'key' => 'storage',
            'section' => 'System',
            'icon' => 'database',
            'label' => 'Archive storage',
            'description' => 'The bucket recordings are cut from, and how a player is handed their segments.',
            'cards' => [
                [
                    'key' => 'disk',
                    'label' => 'Disk and quota',
                    'fields' => [
                        [
                            'key' => 'archive_disk',
                            'label' => 'Disk',
                            'type' => 'select',
                            'store' => 'stream',
                            'config' => 'stream.archive_disk',
                            'options' => [
                                'dvr' => 'dvr - dedicated archive bucket',
                                's3' => 's3 - one bucket for everything',
                            ],
                            'rules' => ['nullable', 'string', 'in:dvr,s3'],
                        ],
                        [
                            'key' => 'archive_quota_bytes',
                            'label' => 'Quota (bytes)',
                            'type' => 'text',
                            'store' => 'stream',
                            'config' => 'stream.archive_quota_bytes',
                            'cast' => 'int',
                            'rules' => ['nullable', 'integer', 'min:0'],
                        ],
                    ],
                ],
                [
                    'key' => 'bucket',
                    'label' => 'Archive bucket (dvr)',
                    // These seven write filesystems.disks.dvr.*, whatever the disk
                    // above is set to. The general s3 disk is bootstrap infrastructure -
                    // branding, emotes and thumbnails resolve it by name at every call
                    // site - so it stays in .env and this card goes inert instead of
                    // pretending to edit it.
                    'inert_when' => [
                        'field' => 'archive_disk',
                        'is' => ['s3'],
                        'description' => 'The s3 disk is configured in .env.',
                    ],
                    // The card that carries the credentials carries the button that
                    // proves them.
                    'render' => 'storage_test',
                    'fields' => [
                        [
                            'key' => 'archive_s3_endpoint',
                            'label' => 'Endpoint',
                            'type' => 'url',
                            'config' => 'filesystems.disks.dvr.endpoint',
                            'rules' => ['nullable', 'url', 'max:2048'],
                        ],
                        [
                            'key' => 'archive_s3_bucket',
                            'label' => 'Bucket',
                            'type' => 'text',
                            'config' => 'filesystems.disks.dvr.bucket',
                            'rules' => ['nullable', 'string', 'max:255'],
                        ],
                        [
                            'key' => 'archive_s3_region',
                            'label' => 'Region',
                            'type' => 'text',
                            'config' => 'filesystems.disks.dvr.region',
                            'rules' => ['nullable', 'string', 'max:64'],
                        ],
                        [
                            'key' => 'archive_s3_key',
                            'label' => 'Access key ID',
                            'type' => 'password',
                            'config' => 'filesystems.disks.dvr.key',
                            'secure' => true,
                            'rules' => ['nullable', 'string', 'max:255'],
                        ],
                        [
                            'key' => 'archive_s3_secret',
                            'label' => 'Secret access key',
                            'type' => 'password',
                            'config' => 'filesystems.disks.dvr.secret',
                            'secure' => true,
                            'rules' => ['nullable', 'string', 'max:255'],
                        ],
                        [
                            'key' => 'archive_s3_url',
                            'label' => 'Public base URL',
                            'type' => 'url',
                            'config' => 'filesystems.disks.dvr.url',
                            'rules' => ['nullable', 'url', 'max:2048'],
                        ],
                        [
                            'key' => 'archive_s3_path_style',
                            'label' => 'Path-style addressing',
                            'type' => 'toggle',
                            'config' => 'filesystems.disks.dvr.use_path_style_endpoint',
                            'rules' => ['boolean'],
                        ],
                    ],
                ],
                [
                    'key' => 'playback',
                    'label' => 'Playback',
                    'fields' => [
                        [
                            'key' => 'archive_url_mode',
                            'label' => 'Segment URLs',
                            'type' => 'select',
                            'store' => 'stream',
                            'config' => 'stream.archive_url_mode',
                            'options' => [
                                'signed' => 'Signed URLs',
                                'proxy' => 'Proxy through this app',
                            ],
                            'rules' => ['required', 'string', 'in:signed,proxy'],
                        ],
                        [
                            'key' => 'archive_url_ttl',
                            'label' => 'Signed URL lifetime (seconds)',
                            'type' => 'text',
                            'store' => 'stream',
                            'config' => 'stream.archive_url_ttl',
                            'cast' => 'int',
                            'rules' => ['nullable', 'integer', 'min:300', 'max:604800'],
                        ],
                        [
                            'key' => 'archive_source_in_master',
                            'label' => 'Advertise the source rendition',
                            'type' => 'toggle',
                            'store' => 'stream',
                            'config' => 'stream.archive_source_in_master',
                            'rules' => ['boolean'],
                        ],
                    ],
                ],
            ],
        ],

        [
            'key' => 'playback',
            'section' => 'System',
            'icon' => 'shield-check',
            'label' => 'Tokens and keys',
            'description' => 'The secrets a viewer\'s access is signed with, and the keys this installation hands to its own tools.',
            'cards' => [
                [
                    'key' => 'viewer_tokens',
                    'label' => 'Viewer tokens',
                    'fields' => [
                        [
                            'key' => 'hls_viewer_secret',
                            'label' => 'Viewer token secret',
                            'type' => 'password',
                            'store' => 'stream',
                            'config' => 'stream.token.viewer_secret',
                            'secure' => true,
                            'full' => true,
                            'rules' => ['nullable', 'string', 'min:32', 'max:255'],
                        ],
                        [
                            'key' => 'hls_embed_secret',
                            'label' => 'Embed token secret',
                            'type' => 'password',
                            'store' => 'stream',
                            'config' => 'stream.token.embed_secret',
                            'secure' => true,
                            'full' => true,
                            'rules' => ['nullable', 'string', 'min:32', 'max:255'],
                        ],
                        [
                            'key' => 'hls_token_ttl',
                            'label' => 'Viewer token lifetime (seconds)',
                            'type' => 'text',
                            'store' => 'stream',
                            'config' => 'stream.token.ttl',
                            'cast' => 'int',
                            'rules' => ['nullable', 'integer', 'min:60', 'max:86400'],
                        ],
                        [
                            'key' => 'hls_token_leeway',
                            'label' => 'Expiry leeway (seconds)',
                            'type' => 'text',
                            'store' => 'stream',
                            'config' => 'stream.token.leeway',
                            'cast' => 'int',
                            'rules' => ['nullable', 'integer', 'min:0', 'max:600'],
                        ],
                        [
                            'key' => 'hls_token_bucket',
                            'label' => 'Segment token reuse window (seconds)',
                            'type' => 'text',
                            'store' => 'stream',
                            'config' => 'stream.token.bucket',
                            'cast' => 'int',
                            'rules' => ['nullable', 'integer', 'min:1', 'max:600'],
                        ],
                        [
                            'key' => 'hls_token_refresh_margin',
                            'label' => 'Token refresh margin (seconds)',
                            'type' => 'text',
                            'store' => 'stream',
                            'config' => 'stream.token.refresh_margin',
                            'cast' => 'int',
                            'rules' => ['nullable', 'integer', 'min:30', 'max:3600'],
                        ],
                    ],
                ],
                [
                    'key' => 'service_keys',
                    'label' => 'Service keys',
                    'fields' => [
                        [
                            'key' => 'system_streamkey',
                            'label' => 'System streamkey',
                            'type' => 'secret',
                            'store' => 'stream',
                            'config' => 'stream.system_streamkey',
                            'secure' => true,
                            'full' => true,
                            'helper' => 'What the thumbnailer, the archive uploader and the operator preview authenticate with.',
                            'rules' => ['nullable', 'string', 'min:16', 'max:255'],
                        ],
                        [
                            'key' => 'recording_api_key',
                            'label' => 'Recording API key',
                            'type' => 'secret',
                            'store' => 'app',
                            'config' => 'app.recording_api_key',
                            'secure' => true,
                            'full' => true,
                            'helper' => 'Sent by the recording API as X-Recording-Api-Key. Generating a new one rotates it.',
                            'rules' => ['nullable', 'string', 'min:16', 'max:255'],
                            'empty_note' => 'Nothing set: the recording API answers every request with 401.',
                            'dirty_note' => 'Not in effect until you save, and every caller has to be given the new key.',
                        ],
                    ],
                ],
                [
                    'key' => 'surfaces',
                    'label' => 'Control surfaces',
                    'description' => 'One key for the installation: a surface names the source it drives in its request, and the people who run the rooms are the same people either way.',
                    'note' => [
                        'label' => 'Download the Companion module',
                        'icon' => 'download',
                        'text' => 'Companion does not ship the Stream Control module. On the Companion machine: Modules, then Import module package. The link serves the newest released build.',
                        'url_config' => 'stream.companion_module_url',
                    ],
                    'fields' => [
                        [
                            'key' => 'control_key',
                            'label' => 'Control key',
                            'type' => 'secret',
                            'store' => 'stream',
                            'full' => true,
                            'helper' => 'Sent by the surface as X-Companion-Token. Generating a new one rotates it, and every surface has to be reconfigured. Empty switches the control API off.',
                            'rules' => ['nullable', 'string', 'min:16', 'max:255'],
                            'empty_note' => 'Nothing set: the control API answers every request with 401.',
                            'dirty_note' => 'Not in effect until you save, and every surface has to be given the new key.',
                        ],
                    ],
                ],
                [
                    'key' => 'imports',
                    'label' => 'Imports',
                    'note' => [
                        'text' => 'The tool encodes on the importing machine and uploads straight to the archive. It needs ffmpeg installed; see docs/admin/archive-import.md.',
                        'downloads_config' => 'stream.import_cli_base_url',
                    ],
                    'fields' => [
                        [
                            'key' => 'import_key',
                            'label' => 'Import key',
                            'type' => 'secret',
                            'store' => 'stream',
                            'full' => true,
                            'helper' => 'Sent by streaming-archiver as X-Import-Key, or set as ARCHIVER_KEY in its environment. Generating a new one rotates it. Empty switches importing off.',
                            'rules' => ['nullable', 'string', 'min:16', 'max:255'],
                            'empty_note' => 'Nothing set: the import API answers every request with 401.',
                            'dirty_note' => 'Not in effect until you save, and anyone importing has to be given the new key.',
                        ],
                    ],
                ],
            ],
        ],

        [
            'key' => 'notifications',
            'section' => 'System',
            'icon' => 'bell',
            'label' => 'Notifications',
            'columns' => 1,
            'description' => 'How long the installation keeps its hand on the send button, and the bot it sends through. Which chats hear what is decided per chat in /manage > Telegram; a viewer subscribes for themselves.',
            'cards' => [
                [
                    'key' => 'bot',
                    'label' => 'Telegram bot',
                    'fields' => [
                        [
                            'key' => 'telegram_bot_token',
                            'label' => 'Bot token',
                            'type' => 'password',
                            'store' => 'telegram',
                            'config' => 'telegram.bot_token',
                            'full' => true,
                            'helper' => 'From @BotFather, of the form 123456:ABC-DEF. Saving one registers the webhook; clearing it takes the bot off the air and leaves the linked chats alone.',
                            'rules' => ['nullable', 'string', 'max:255'],
                        ],
                        [
                            'key' => 'telegram_bot_username',
                            'label' => 'Bot @name',
                            'type' => 'text',
                            'store' => 'telegram',
                            'config' => 'telegram.bot_username',
                            'helper' => 'Filled in from the token when one is saved. Without the @.',
                            'rules' => ['nullable', 'string', 'max:64', 'regex:/^@?[A-Za-z0-9_]+$/'],
                        ],
                        [
                            'key' => 'telegram_show_lead_minutes',
                            'label' => 'Announce shows this early (minutes)',
                            'type' => 'text',
                            'store' => 'telegram',
                            'config' => 'telegram.show_lead_minutes',
                            'cast' => 'int',
                            'rules' => ['nullable', 'integer', 'min:1', 'max:120'],
                        ],
                    ],
                ],
                [
                    'key' => 'recordings',
                    'label' => 'Recordings',
                    'fields' => [
                        [
                            'key' => 'notification_delay_hours',
                            'label' => 'Hold new recordings for (hours)',
                            'type' => 'text',
                            'store' => 'notifications',
                            'config' => 'notifications.delay_hours',
                            'cast' => 'int',
                            'helper' => 'Nothing is queued during the window, so unpublishing inside it cancels the send.',
                            'rules' => ['nullable', 'integer', 'min:1', 'max:168'],
                        ],
                    ],
                ],
            ],
        ],

        [
            'key' => 'reset',
            'icon' => 'refresh-cw',
            'label' => 'Reset',
            'description' => 'Delete every saved value and go back to what the software ships with.',
            // A pane with no fields and a button instead of a form.
            'action' => 'reset',
            'fields' => [],
        ],

    ],

    /*
     | Panes that were merged into another one. A pane's URL is printed in the admin
     | docs and pasted between operators, so a key that stops existing answers a
     | redirect to the pane its fields now live on rather than a 404.
     */
    'moved' => [
        'login' => 'identity',
        'auth' => 'identity',
        'links' => 'branding',
        'look' => 'branding',
        'imports' => 'playback',
        'control' => 'playback',
        'telegram' => 'notifications',
    ],

    /*
     | Config namespace every group falls back to unless a field overrides it with
     | its own `store`. Branding is the bulk of it; the pretalx and control groups
     | name their own.
     */
    'store' => 'branding',

];
