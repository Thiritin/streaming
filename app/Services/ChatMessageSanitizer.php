<?php

namespace App\Services;

use App\Models\User;

/**
 * Cleans up raw chat input. Output stays plain text: escaping and emote/mention
 * rendering happen in the client, so no markup is produced or preserved here.
 */
class ChatMessageSanitizer
{
    /**
     * Sanitize a chat message.
     */
    public function sanitize(string $message, ?User $user = null): string
    {
        $message = $this->stripControlCharacters($message);
        $message = $this->collapseWhitespace($message);
        $message = $this->filterUrls($message);
        $message = trim($message);

        if (mb_strlen($message) > $this->getMaxLength()) {
            $message = mb_substr($message, 0, $this->getMaxLength());
        }

        return $message;
    }

    /**
     * Drop zero-width and control characters used to break layouts or evade filters.
     */
    protected function stripControlCharacters(string $message): string
    {
        // C0/C1 controls (keeping \n and \t), zero-width marks and bidi overrides.
        $message = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $message);

        return preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{206F}\x{FEFF}]/u', '', $message);
    }

    /**
     * Cap runaway newlines and spaces so a single message cannot own the viewport.
     */
    protected function collapseWhitespace(string $message): string
    {
        $message = str_replace(["\r\n", "\r", "\t"], ["\n", "\n", ' '], $message);
        $message = preg_replace('/\n{3,}/', "\n\n", $message);

        return preg_replace('/[ ]{4,}/', '   ', $message);
    }

    /**
     * Replace links to non-whitelisted domains with a placeholder.
     */
    protected function filterUrls(string $message): string
    {
        $urlPattern = '/(?:https?:\/\/|www\.)(?:[a-zA-Z0-9-]+\.)+[a-zA-Z]{2,}(?:\/[^\s]*)?|(?:[a-zA-Z0-9-]+\.)+(?:com|org|net|edu|gov|io|co|uk|de|fr|jp|cn|in|br|au|ca|ru|ch|se|no|dk|fi|nl|be|at|pl|es|it|pt|gr|tr|ae|sg|hk|my|th|id|ph|vn|kr|tw|mx|ar|cl|pe|za)(?:\/[^\s]*)?/i';

        return preg_replace_callback($urlPattern, function ($matches) {
            $url = $matches[0];

            foreach ($this->getAllowedDomains() as $domain) {
                if (stripos($url, $domain) !== false) {
                    return $url;
                }
            }

            return '[url removed]';
        }, $message);
    }

    /**
     * Check if message contains only allowed characters.
     */
    public function hasDisallowedCharacters(string $message): bool
    {
        return (bool) preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $message);
    }

    /**
     * True when a message carries no visible content.
     */
    public function isEffectivelyEmpty(string $message): bool
    {
        return trim($message) === '';
    }

    public function getMaxLength(): int
    {
        return (int) config('chat.default.maxMessageLength', 500);
    }

    /**
     * Domains whose links survive sanitising.
     *
     * Written one per line in the settings pane and held as one string, so it is split
     * here. A list is still accepted, which is how config/chat.php was read before and
     * how a test sets it.
     *
     * @return array<int, string>
     */
    public function getAllowedDomains(): array
    {
        $domains = config('chat.allowed_domains', []);

        if (! is_array($domains)) {
            $domains = preg_split('/[\s,]+/', (string) $domains) ?: [];
        }

        return array_values(array_filter(array_map('trim', $domains)));
    }
}
