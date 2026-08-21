<?php

namespace App\Console\Commands;

use App\Services\Telegram\TelegramClient;
use App\Support\TelegramSettings;
use Illuminate\Console\Command;

/**
 * The webhook, from a shell.
 *
 * Saving the token in /manage registers it already, so this is for the cases the panel
 * cannot cover: a deploy that moved the app to a new domain, a suspicion that Telegram
 * is pointed somewhere stale, or rotating the secret without touching the token.
 */
class TelegramWebhookCommand extends Command
{
    protected $signature = 'telegram:webhook {action=info : info, set, rotate or delete}';

    protected $description = 'Inspect or register the Telegram webhook for this installation';

    public function handle(TelegramClient $client): int
    {
        if (! $client->enabled()) {
            $this->error('No bot token saved, or the telegram feature is off. Set both at /manage > Settings.');

            return self::FAILURE;
        }

        return match ($this->argument('action')) {
            'set' => $this->register($client),
            'rotate' => $this->rotate($client),
            'delete' => $this->remove($client),
            default => $this->status($client),
        };
    }

    private function register(TelegramClient $client): int
    {
        $result = $client->registerWebhook();

        if ($result['ok'] ?? false) {
            $this->info('Webhook registered at '.TelegramSettings::webhookUrl());

            return self::SUCCESS;
        }

        $this->error((string) ($result['description'] ?? 'Telegram refused it.'));

        return self::FAILURE;
    }

    private function rotate(TelegramClient $client): int
    {
        TelegramSettings::rotateWebhookSecret();

        $this->line('Secret rotated. Re-registering so Telegram uses the new one.');

        return $this->register($client);
    }

    private function remove(TelegramClient $client): int
    {
        $result = $client->deleteWebhook();

        if ($result['ok'] ?? false) {
            $this->info('Webhook removed. Nothing will be delivered until it is set again.');

            return self::SUCCESS;
        }

        $this->error((string) ($result['description'] ?? 'Telegram refused it.'));

        return self::FAILURE;
    }

    private function status(TelegramClient $client): int
    {
        $me = $client->me();
        $hook = $client->webhookInfo()['result'] ?? [];

        $this->table(['', ''], [
            ['Bot', isset($me['username']) ? '@'.$me['username'] : 'unknown'],
            ['Expected URL', TelegramSettings::webhookUrl()],
            ['Registered URL', $hook['url'] ?? 'none'],
            ['Pending updates', (string) ($hook['pending_update_count'] ?? 0)],
            ['Last error', $hook['last_error_message'] ?? 'none'],
        ]);

        return self::SUCCESS;
    }
}
