<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Models\Source;
use App\Models\TelegramChat;
use App\Models\TelegramLinkCode;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramNotifier;
use App\Support\Manage\Action;
use App\Support\Manage\Column;
use App\Support\Manage\Status;
use App\Support\Manage\Table;
use App\Support\Manage\Toast;
use App\Support\TelegramSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Inertia\Response;

/**
 * The chats the installation's bot talks to.
 *
 * The bot itself is one token in Settings > Telegram. This page is the other half: which
 * chats exist, what each of them is told, and whether it is allowed to press anything.
 *
 * Two ways in, because groups and direct messages behave differently. A group is linked
 * with a code pasted into it, since the bot cannot address a group it has never heard
 * from. A chat id can also be typed in directly, which is what a person who already
 * knows their own id does.
 */
class TelegramController extends Controller
{
    public function index(Request $request, TelegramClient $client): Response
    {
        $this->authorize('viewAny', TelegramChat::class);

        $table = Table::make(TelegramChat::query()->with('linker'))
            ->name('telegram-chats')
            ->columns([
                Column::text('title', 'Chat')->searchable()->sortable(),
                Column::copyable('chat_id', 'Chat ID'),
                Column::text('topic', 'Topic'),
                Column::badge('mode', 'Buttons'),
                Column::text('receives', 'Receives'),
                Column::text('sources', 'Sources'),
                Column::badge('state', 'State'),
                Column::text('last_message', 'Last Post')->sortable('last_message_at'),
                Column::text('linked', 'Linked')->toggleable(hiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->rows(fn (TelegramChat $chat) => $this->row($chat))
            ->recordUrl(fn (TelegramChat $chat) => route('manage.telegram.edit', $chat))
            ->rowActions(fn (TelegramChat $chat) => $this->rowActions($chat))
            ->pageActions($this->pageActions());

        return inertia('Manage/Telegram/Index', [
            'table' => $table->toArray($request),
            'bot' => $this->bot($client),
            'codes' => $this->codes(),
        ]);
    }

    /**
     * Mint a code for `/link`. Short-lived on purpose: it is about to be pasted into a
     * room full of people.
     */
    public function code(Request $request): RedirectResponse
    {
        $this->authorize('create', TelegramChat::class);

        $code = TelegramLinkCode::mint($request->user());

        Toast::flashSuccess(
            'Link code ready',
            "Send /link {$code->code} in the chat within the next ".
            $code->expires_at->diffInMinutes(now()->subSecond()).' minutes.',
        );

        return back();
    }

    /**
     * Add a chat by its id, for the direct message case where nobody wants to bother
     * with a code. The bot still cannot write there until that person has said
     * something to it once - Telegram's rule, not ours - which is what the test post
     * on the row is for.
     */
    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', TelegramChat::class);

        $validated = $request->validate([
            'chat_id' => [
                'required', 'string', 'max:64', 'regex:/^-?\d+$/',
                // One row per topic, so the same group may appear several times: the pair
                // is what has to be unique, not the chat id.
                Rule::unique('telegram_chats', 'chat_id')
                    ->where('thread_id', (int) $request->input('thread_id', 0)),
            ],
            'thread_id' => ['nullable', 'integer', 'min:0'],
            'title' => ['nullable', 'string', 'max:120'],
        ], [
            'chat_id.regex' => 'A chat id is a number, negative for groups. Send /chatid to the bot to find it.',
            'chat_id.unique' => 'That chat (and topic) is already in the list.',
        ]);

        $chat = TelegramChat::create([
            'chat_id' => $validated['chat_id'],
            'thread_id' => (int) ($validated['thread_id'] ?? 0),
            'title' => $validated['title'] ?? null,
            'enabled' => true,
            'linked_by' => $request->user()->id,
            'linked_at' => now(),
        ]);

        Toast::flashSuccess('Chat added', 'Nothing is switched on for it yet.');

        return to_route('manage.telegram.edit', $chat);
    }

    public function edit(TelegramChat $telegram): Response
    {
        $this->authorize('view', $telegram);

        return inertia('Manage/Telegram/Form', [
            'chat' => [
                'id' => $telegram->id,
                'chat_id' => $telegram->chat_id,
                'thread_id' => $telegram->thread_id,
                'topic' => $telegram->isTopic() ? ($telegram->topic_title ?: 'Topic '.$telegram->thread_id) : null,
                'title' => $telegram->title,
                'type' => $telegram->type,
                'enabled' => $telegram->enabled,
                'interactive' => $telegram->interactive,
                'notify_feedback' => $telegram->notify_feedback,
                'notify_shows' => $telegram->notify_shows,
                'notify_recordings' => $telegram->notify_recordings,
                'notify_sources' => $telegram->notify_sources,
                'notify_comments' => $telegram->notify_comments,
                'source_ids' => array_map('intval', $telegram->source_ids ?? []),
                'last_error' => $telegram->last_error,
                'last_message' => $telegram->last_message_at?->diffForHumans(),
                'linked_by' => $telegram->linker?->name,
            ],
            'sources' => Source::orderBy('name')->get(['id', 'name'])
                ->map(fn (Source $source) => ['value' => $source->id, 'label' => $source->name])
                ->all(),
        ]);
    }

    public function update(Request $request, TelegramChat $telegram): RedirectResponse
    {
        $this->authorize('update', $telegram);

        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:120'],
            'enabled' => ['boolean'],
            'interactive' => ['boolean'],
            'notify_feedback' => ['boolean'],
            'notify_shows' => ['boolean'],
            'notify_recordings' => ['boolean'],
            'notify_sources' => ['boolean'],
            'notify_comments' => ['boolean'],
            'source_ids' => ['nullable', 'array'],
            'source_ids.*' => ['integer', 'exists:sources,id'],
        ]);

        $telegram->forceFill([
            'title' => $validated['title'] ?? $telegram->title,
            'enabled' => (bool) ($validated['enabled'] ?? false),
            'interactive' => (bool) ($validated['interactive'] ?? false),
            'notify_feedback' => (bool) ($validated['notify_feedback'] ?? false),
            'notify_recordings' => (bool) ($validated['notify_recordings'] ?? false),
            'notify_sources' => (bool) ($validated['notify_sources'] ?? false),
            'notify_comments' => (bool) ($validated['notify_comments'] ?? false),
            'notify_shows' => (bool) ($validated['notify_shows'] ?? false),
            'source_ids' => array_values(array_map('intval', $validated['source_ids'] ?? [])),
            // Re-enabling is how an operator says the reason it was switched off is
            // dealt with, so the stale reason goes with it.
            'last_error' => ($validated['enabled'] ?? false) ? null : $telegram->last_error,
        ])->save();

        Toast::flashSuccess('Chat updated', $telegram->interactive
            ? 'Anyone in this chat can start and end its shows.'
            : 'This chat is told things; it cannot press anything.');

        return to_route('manage.telegram.index');
    }

    public function test(TelegramChat $telegram, TelegramNotifier $notifier): RedirectResponse
    {
        $this->authorize('update', $telegram);

        if ($notifier->test($telegram->refresh())) {
            Toast::flashSuccess('Test posted', 'The bot can write to '.$telegram->label().'.');

            return back();
        }

        Toast::flashDanger(
            'Nothing was posted',
            $telegram->refresh()->last_error ?: 'Telegram refused the message.',
        );

        return back();
    }

    public function destroy(TelegramChat $telegram): RedirectResponse
    {
        $this->authorize('delete', $telegram);

        $label = $telegram->label();
        $telegram->delete();

        Toast::flashSuccess('Chat removed', "Nothing more is posted to {$label}.");

        return to_route('manage.telegram.index');
    }

    /**
     * Who the token belongs to and whether Telegram is actually pointed at us. Cached
     * briefly: it is two API calls and the page polls.
     *
     * @return array<string, mixed>
     */
    private function bot(TelegramClient $client): array
    {
        if (! $client->enabled()) {
            return [
                'configured' => false,
                'webhook_url' => TelegramSettings::webhookUrl(),
                'settings_url' => route('manage.settings.group', 'telegram'),
            ];
        }

        return Cache::remember('telegram_bot_status', 30, function () use ($client) {
            $me = $client->me();
            $hook = $client->webhookInfo();
            $info = $hook['result'] ?? [];

            return [
                'configured' => true,
                'username' => isset($me['username']) ? '@'.$me['username'] : null,
                'name' => $me['first_name'] ?? null,
                'webhook_url' => TelegramSettings::webhookUrl(),
                'webhook_registered' => ($info['url'] ?? '') === TelegramSettings::webhookUrl(),
                'webhook_error' => $info['last_error_message'] ?? null,
                'pending' => $info['pending_update_count'] ?? 0,
                'settings_url' => route('manage.settings.group', 'telegram'),
            ];
        });
    }

    /**
     * The codes that can still be used, newest first. A used or expired one is history
     * and does not need showing.
     *
     * @return array<int, array<string, mixed>>
     */
    private function codes(): array
    {
        return TelegramLinkCode::whereNull('used_at')
            ->where('expires_at', '>', now())
            ->latest()
            ->take(3)
            ->get()
            ->map(fn (TelegramLinkCode $code) => [
                'code' => $code->code,
                'command' => '/link '.$code->code,
                'expires' => $code->expires_at->diffForHumans(),
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function row(TelegramChat $chat): array
    {
        $receives = array_filter([
            $chat->notify_shows ? 'Shows' : null,
            $chat->notify_recordings ? 'Recordings' : null,
            $chat->notify_sources ? 'Sources' : null,
            $chat->notify_feedback ? 'Feedback' : null,
            $chat->notify_comments ? 'Reported comments' : null,
        ]);

        return [
            'id' => $chat->id,
            'title' => $chat->label(),
            'chat_id' => $chat->chat_id,
            'topic' => $chat->isTopic() ? ($chat->topic_title ?: (string) $chat->thread_id) : 'Whole chat',
            'mode' => Status::toggle($chat->interactive, 'Actions', 'Info only', Status::WARN, Status::IDLE),
            'receives' => $receives === [] ? 'Nothing' : implode(', ', $receives),
            'sources' => ($chat->source_ids ?? []) === [] ? 'All' : $chat->sources()->pluck('name')->implode(', '),
            'state' => $chat->enabled
                ? Status::make('Active', Status::OK, 'circle-check')
                : Status::make($chat->last_error ? 'Failing' : 'Off', Status::DANGER, 'circle-x'),
            'last_message' => $chat->last_message_at?->diffForHumans() ?? 'Never',
            'linked' => $chat->linker?->name ?? 'By code',
        ];
    }

    /**
     * @return array<int, Action>
     */
    private function rowActions(TelegramChat $chat): array
    {
        if (! request()->user()->can('update', $chat)) {
            return [];
        }

        return [
            Action::link('edit', 'Edit', route('manage.telegram.edit', $chat))->icon('pencil'),
            Action::post('test', 'Send test post', route('manage.telegram.test', $chat))->icon('send'),
            Action::delete('delete', 'Remove', route('manage.telegram.destroy', $chat))
                ->icon('trash-2')
                ->tone(Status::DANGER)
                ->confirm(
                    'Remove chat',
                    "Nothing more is posted to {$chat->label()}. Messages already there stay, and stop being updated.",
                    'Remove',
                ),
        ];
    }

    /**
     * @return array<int, Action>
     */
    private function pageActions(): array
    {
        if (! request()->user()->can('create', TelegramChat::class)) {
            return [];
        }

        return [
            Action::post('code', 'New link code', route('manage.telegram.code'))->icon('plus'),
            Action::post('add', 'Add by chat ID', route('manage.telegram.store'))
                ->icon('message-square')
                ->fields([
                    [
                        'key' => 'chat_id',
                        'label' => 'Chat ID',
                        'type' => 'text',
                        'helper' => 'Send /chatid to the bot in that chat to find it. Groups are negative.',
                    ],
                    [
                        'key' => 'thread_id',
                        'label' => 'Topic ID',
                        'type' => 'number',
                        'helper' => 'Only for a forum group, when posts should land in one topic. /chatid names it. Empty means the whole chat.',
                    ],
                    [
                        'key' => 'title',
                        'label' => 'Name',
                        'type' => 'text',
                        'helper' => 'What this chat is called here.',
                    ],
                ]),
        ];
    }
}
