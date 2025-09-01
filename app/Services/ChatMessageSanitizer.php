<?php

namespace App\Services;

class ChatMessageSanitizer
{
    protected array $allowedDomains = [
        'eurofurence.org',
    ];

    protected int $maxMessageLength = 500;

    protected int $maxWordLength = 30;

    /**
     * Sanitize a chat message
     */
    public function sanitize(string $message, ?\App\Models\User $user = null): string
    {
        // Trim the message
        $message = trim($message);

        // Limit length first
        if (mb_strlen($message) > $this->maxMessageLength) {
            $message = mb_substr($message, 0, $this->maxMessageLength);
        }

        // Process emotes if user is provided
        if ($user) {
            $emoteService = app(\App\Services\EmoteService::class);
            $parsed = $emoteService->parseMessage($message, $user);
            $message = $parsed['message'];
        }

        // Process URLs - replace non-whitelisted URLs with [url removed]
        $message = $this->filterUrls($message);

        // Escape HTML entities to prevent XSS (but preserve emote tags)
        $message = $this->escapeHtmlPreservingEmotes($message);

        // Break long words that could break layout (this adds characters but is for display)
        $message = $this->breakLongWords($message);

        return $message;
    }

    /**
     * Escape HTML but preserve emote tags.
     */
    protected function escapeHtmlPreservingEmotes(string $message): string
    {
        // Temporarily replace emote tags with placeholders
        $emotePattern = '/<emote data-name="([^"]+)" data-url="([^"]+)"(?: data-size="([^"]+)")?><\/emote>/';
        $emotePlaceholders = [];
        $placeholderIndex = 0;

        $message = preg_replace_callback($emotePattern, function ($matches) use (&$emotePlaceholders, &$placeholderIndex) {
            $placeholder = "[[EMOTE_PLACEHOLDER_$placeholderIndex]]";
            $emotePlaceholders[$placeholder] = $matches[0];
            $placeholderIndex++;

            return $placeholder;
        }, $message);

        // Escape HTML
        $message = htmlspecialchars($message, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Restore emote tags
        foreach ($emotePlaceholders as $placeholder => $emoteTag) {
            $message = str_replace($placeholder, $emoteTag, $message);
        }

        return $message;
    }

    /**
     * Filter URLs, keeping only whitelisted domains
     */
    protected function filterUrls(string $message): string
    {
        // Match URLs including those without protocol
        $urlPattern = '/(?:https?:\/\/|www\.)(?:[a-zA-Z0-9-]+\.)+[a-zA-Z]{2,}(?:\/[^\s]*)?|(?:[a-zA-Z0-9-]+\.)+(?:com|org|net|edu|gov|io|co|uk|de|fr|jp|cn|in|br|au|ca|ru|ch|se|no|dk|fi|nl|be|at|pl|es|it|pt|gr|tr|ae|sg|hk|my|th|id|ph|vn|kr|tw|mx|ar|cl|pe|co|za)(?:\/[^\s]*)?/i';

        return preg_replace_callback($urlPattern, function ($matches) {
            $url = $matches[0];

            // Check if URL is from allowed domain
            foreach ($this->allowedDomains as $domain) {
                // Check if the URL contains the allowed domain
                if (stripos($url, $domain) !== false) {
                    return $url;
                }
            }

            return '[url removed]';
        }, $message);
    }

    /**
     * Break long words to prevent layout breaking
     */
    protected function breakLongWords(string $message): string
    {
        // First, protect emote tags from being broken
        $emotePattern = '/<emote[^>]*><\/emote>/';
        $emotePlaceholders = [];
        $placeholderIndex = 0;
        
        $message = preg_replace_callback($emotePattern, function ($matches) use (&$emotePlaceholders, &$placeholderIndex) {
            $placeholder = "[[EMOTE_PROTECT_$placeholderIndex]]";
            $emotePlaceholders[$placeholder] = $matches[0];
            $placeholderIndex++;
            return $placeholder;
        }, $message);

        // Now break long words
        $words = explode(' ', $message);
        $processedWords = [];

        foreach ($words as $word) {
            // Skip breaking if it's an emote placeholder
            if (strpos($word, '[[EMOTE_PROTECT_') !== false) {
                $processedWords[] = $word;
                continue;
            }
            
            // If word is longer than max length, insert zero-width spaces for wrapping
            if (mb_strlen($word) > $this->maxWordLength) {
                // Insert zero-width space every N characters
                $word = implode('&#8203;', mb_str_split($word, $this->maxWordLength));
            }
            $processedWords[] = $word;
        }

        $message = implode(' ', $processedWords);
        
        // Restore emote tags
        foreach ($emotePlaceholders as $placeholder => $emoteTag) {
            $message = str_replace($placeholder, $emoteTag, $message);
        }

        return $message;
    }

    /**
     * Check if message contains only allowed characters
     */
    public function hasDisallowedCharacters(string $message): bool
    {
        // Allow alphanumeric, common punctuation, spaces, and common Unicode ranges
        // This pattern allows most legitimate chat but blocks control characters
        $allowedPattern = '/^[\p{L}\p{N}\p{P}\p{S}\p{Zs}\p{Emoji}]+$/u';

        return ! preg_match($allowedPattern, $message);
    }

    /**
     * Get the maximum message length
     */
    public function getMaxLength(): int
    {
        return $this->maxMessageLength;
    }

    /**
     * Get allowed domains for URLs
     */
    public function getAllowedDomains(): array
    {
        return $this->allowedDomains;
    }
}
