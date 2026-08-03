<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Editable settings
    |--------------------------------------------------------------------------
    |
    | The /manage settings page is generated from this file. Each group becomes a
    | section, each field a control, and the validation rules here are the rules the
    | update request applies, so adding a knob is one entry rather than a form, a
    | request class and a page change.
    |
    | `store` is the config namespace a field falls back to when nothing has been
    | saved, and `key` is the flat key written to the settings table. Both together
    | mean a field can be moved to another config file without touching saved data.
    |
    | Types: text, textarea, url, color, image, video. Uploads name a purpose from
    | config/manage.php, which owns the disk, directory and size limits.
    |
    */

    'groups' => [

        [
            'key' => 'identity',
            'label' => 'Identity',
            'description' => 'Who this installation belongs to, and where people get an account.',
            'columns' => 2,
            'fields' => [
                [
                    'key' => 'convention_name',
                    'label' => 'Convention name',
                    'type' => 'text',
                    'helper' => 'Name of the convention, used in page copy.',
                    'rules' => ['required', 'string', 'max:255'],
                ],
                [
                    'key' => 'site_name',
                    'label' => 'Site name',
                    'type' => 'text',
                    'helper' => 'Name of this streaming site, used in the header and page titles.',
                    'rules' => ['required', 'string', 'max:255'],
                ],
                [
                    'key' => 'identity_name',
                    'label' => 'Identity provider name',
                    'type' => 'text',
                    'helper' => 'Name of the identity provider people sign in with.',
                    'rules' => ['required', 'string', 'max:255'],
                ],
                [
                    'key' => 'identity_register_url',
                    'label' => 'Register URL',
                    'type' => 'url',
                    'helper' => 'Where people register a new identity account.',
                    'rules' => ['nullable', 'url', 'max:2048'],
                ],
                [
                    'key' => 'identity_logout_url',
                    'label' => 'Logout URL',
                    'type' => 'url',
                    'helper' => 'Identity provider logout endpoint.',
                    'rules' => ['nullable', 'url', 'max:2048'],
                ],
            ],
        ],

        [
            'key' => 'login',
            'label' => 'Login screen',
            'description' => 'Everything shown to visitors before they sign in.',
            'columns' => 2,
            'fields' => [
                [
                    'key' => 'login_eyebrow',
                    'label' => 'Eyebrow',
                    'type' => 'text',
                    'helper' => 'Small label above the login headline.',
                    'rules' => ['nullable', 'string', 'max:255'],
                ],
                [
                    'key' => 'login_headline',
                    'label' => 'Headline',
                    'type' => 'text',
                    'helper' => 'Main login headline.',
                    'rules' => ['required', 'string', 'max:255'],
                ],
                [
                    'key' => 'login_tagline',
                    'label' => 'Tagline',
                    'type' => 'text',
                    'helper' => 'One line under the headline.',
                    'rules' => ['nullable', 'string', 'max:255'],
                ],
                [
                    'key' => 'login_button_label',
                    'label' => 'Button label',
                    'type' => 'text',
                    'helper' => 'Label on the sign-in button.',
                    'rules' => ['required', 'string', 'max:255'],
                ],
                [
                    'key' => 'login_body',
                    'label' => 'Intro paragraph',
                    'type' => 'textarea',
                    'helper' => 'Paragraph explaining what is needed to watch.',
                    'rules' => ['nullable', 'string', 'max:2000'],
                    'full' => true,
                ],
            ],
        ],

        [
            'key' => 'look',
            'label' => 'Look',
            'description' => 'Logo, accent colour and the login background. Uploads land on the public disk.',
            'columns' => 2,
            'fields' => [
                [
                    'key' => 'primary_color',
                    'label' => 'Accent colour',
                    'type' => 'color',
                    'helper' => 'Pick a preset or set any hex. A full 50-950 ramp is derived from it; empty keeps the built-in palette.',
                    'rules' => ['nullable', 'string', 'regex:/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'],
                    'presets' => \App\Support\ColorPresets::PRESETS,
                ],
                [
                    'key' => 'logo_path',
                    'label' => 'Logo',
                    'type' => 'image',
                    'purpose' => 'branding_logo',
                    'helper' => 'Leave empty to show the site name as text instead.',
                    'rules' => ['nullable', 'string', 'max:2048'],
                ],
                [
                    'key' => 'login_background_image',
                    'label' => 'Login background image',
                    'type' => 'image',
                    'purpose' => 'branding_login_image',
                    'helper' => 'Also used as the poster for the background video.',
                    'rules' => ['nullable', 'string', 'max:2048'],
                ],
                [
                    'key' => 'login_background_video',
                    'label' => 'Login background video',
                    'type' => 'video',
                    'purpose' => 'branding_login_video',
                    'helper' => 'Left empty, the bundled clip is used.',
                    'rules' => ['nullable', 'string', 'max:2048'],
                ],
            ],
        ],

        [
            'key' => 'links',
            'label' => 'Footer links',
            'description' => 'Shown in the footer of the public site, in this order. Add as many as you need; with none, the footer link row is hidden.',
            'columns' => 1,
            'fields' => [
                [
                    'key' => 'footer_links',
                    'label' => 'Links',
                    // A repeater of {label, url} rows, stored as JSON. `itemRules`
                    // are expanded onto values.footer_links.* by Settings::rules().
                    'type' => 'links',
                    'full' => true,
                    'helper' => 'Title and address for each link. Empty rows are dropped on save.',
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

        [
            'key' => 'pretalx',
            'label' => 'Pretalx',
            'description' => 'Where the programme comes from. With these set, /manage > Shows can import sessions from the published schedule.',
            'columns' => 2,
            'fields' => [
                [
                    'key' => 'pretalx_url',
                    'label' => 'Instance URL',
                    'type' => 'url',
                    'store' => 'pretalx',
                    'helper' => 'Root of the pretalx instance, for example https://cfp.example.org.',
                    'rules' => ['nullable', 'url', 'max:2048'],
                ],
                [
                    'key' => 'pretalx_event',
                    'label' => 'Event slug',
                    'type' => 'text',
                    'store' => 'pretalx',
                    'helper' => 'The slug in the pretalx URL, for example my-con-2026.',
                    'rules' => ['nullable', 'string', 'max:255'],
                ],
                [
                    'key' => 'pretalx_token',
                    'label' => 'API token',
                    'type' => 'password',
                    'store' => 'pretalx',
                    'full' => true,
                    'helper' => 'From the pretalx user account, under API tokens. Only needed while the schedule is unpublished or the event is private.',
                    'rules' => ['nullable', 'string', 'max:255'],
                ],
            ],
        ],

    ],

    /*
     | Config namespace every group falls back to unless a field overrides it with
     | its own `store`. Branding is the bulk of it; the pretalx group names its own.
     */
    'store' => 'branding',

];
