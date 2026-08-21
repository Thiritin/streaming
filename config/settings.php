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
    */

    'groups' => [

        [
            'key' => 'identity',
            'blurb' => 'Names and accounts',
            'icon' => 'users',
            'label' => 'Identity',
            'description' => 'Who this installation belongs to, and where people get an account.',
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
            'blurb' => 'Sign-in copy',
            'icon' => 'lock',
            'label' => 'Login screen',
            'description' => 'Everything shown to visitors before they sign in.',
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
            'blurb' => 'Logo and colours',
            'icon' => 'paintbrush',
            'label' => 'Look',
            'description' => 'Logo, accent colour and the login background. Uploads land on the public disk.',
            'fields' => [
                [
                    'key' => 'primary_color',
                    'label' => 'Accent colour',
                    'type' => 'color',
                    'helper' => 'Pick a preset or set any hex. A full 50-950 ramp is derived from it; empty keeps the built-in palette.',
                    'rules' => ['nullable', 'string', 'regex:/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'],
                    'presets' => ColorPresets::PRESETS,
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
            'blurb' => 'Footer links',
            'icon' => 'external-link',
            'label' => 'Footer links',
            'description' => 'Shown in the footer of the public site, in this order. Add as many as you need; with none, the footer link row is hidden.',
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
            'key' => 'announcement',
            'blurb' => 'Front page banner',
            'icon' => 'triangle-alert',
            'label' => 'Announcement',
            'description' => 'One banner across the top of the public site, for whatever everybody watching needs to know right now. Write the short version on the banner and the rest below it, which becomes a page of its own. Clearing the banner text takes the whole thing down whatever the switch says.',
            'fields' => [
                [
                    'key' => 'announcement_enabled',
                    'label' => 'Show the banner',
                    'type' => 'toggle',
                    'store' => 'announcement',
                    'helper' => 'Off hides it everywhere without losing the text you wrote.',
                    'rules' => ['boolean'],
                ],
                [
                    'key' => 'announcement_level',
                    'label' => 'Level',
                    'type' => 'select',
                    'store' => 'announcement',
                    'options' => Announcement::LEVELS,
                    'helper' => 'Colour only. Critical is loud; save it for something that is actually going wrong.',
                    'rules' => ['nullable', 'string', 'in:'.implode(',', array_keys(Announcement::LEVELS))],
                ],
                [
                    'key' => 'announcement_title',
                    'label' => 'Title',
                    'type' => 'text',
                    'store' => 'announcement',
                    'full' => true,
                    'helper' => 'Optional bold line above the text. The banner reads fine without one.',
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
                    'helper' => 'What the read-more link is called, wherever it points. Empty reads "Read more".',
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
            'blurb' => 'What is switched on',
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
            'key' => 'pretalx',
            'blurb' => 'Programme import',
            'icon' => 'calendar',
            'label' => 'Pretalx',
            'description' => 'Where the programme comes from. With these set, /manage > Shows can import sessions from the published schedule.',
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

        [
            'key' => 'control',
            'blurb' => 'Hardware surfaces',
            'icon' => 'radio-tower',
            'label' => 'Control surfaces',
            'description' => 'The key a hardware control surface authenticates with; see docs/admin/companion.md. One key for the installation: a surface names the source it drives in its request, and the people who run the rooms are the same people either way.',
            'columns' => 1,
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
            'blurb' => 'Archive imports',
            'icon' => 'archive',
            'label' => 'Imports',
            'columns' => 1,
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

        [
            'key' => 'telegram',
            'blurb' => 'Bot and chats',
            'icon' => 'send',
            'label' => 'Telegram',
            'columns' => 1,
            'description' => 'One bot for the installation, and as many chats as there are rooms worth telling. Saving a token registers the webhook with Telegram straight away; which chats hear what is decided per chat in /manage > Telegram.',
            'fields' => [
                [
                    'key' => 'telegram_bot_token',
                    'label' => 'Bot token',
                    'type' => 'password',
                    'store' => 'telegram',
                    'full' => true,
                    'helper' => 'From @BotFather, of the form 123456:ABC-DEF. Saving one registers the webhook; clearing it takes the bot off the air and leaves the linked chats alone.',
                    'rules' => ['nullable', 'string', 'max:255'],
                ],
                [
                    'key' => 'telegram_show_lead_minutes',
                    'label' => 'Announce shows this early',
                    'type' => 'text',
                    'store' => 'telegram',
                    'helper' => 'Minutes before a scheduled start. The message carries the Start button, so this is also how early a show can be started from a chat.',
                    'rules' => ['nullable', 'integer', 'min:1', 'max:120'],
                ],
            ],
        ],

        [
            'key' => 'reset',
            'blurb' => 'Back to the defaults',
            'icon' => 'refresh-cw',
            'label' => 'Reset',
            'description' => 'Delete every saved value and go back to what the software ships with.',
            // A pane with no fields and a button instead of a form.
            'action' => 'reset',
            'fields' => [],
        ],

    ],

    /*
     | Config namespace every group falls back to unless a field overrides it with
     | its own `store`. Branding is the bulk of it; the pretalx and control groups
     | name their own.
     */
    'store' => 'branding',

];
