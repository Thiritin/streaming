<?php

namespace App\Services;

use Illuminate\Support\Str;

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
    public function sanitize(string $message): string
    {
        // Trim the message
        $message = trim($message);
        
        // Limit length first
        if (mb_strlen($message) > $this->maxMessageLength) {
            $message = mb_substr($message, 0, $this->maxMessageLength);
        }
        
        // Process URLs - replace non-whitelisted URLs with [url removed]
        $message = $this->filterUrls($message);
        
        // Escape HTML entities to prevent XSS
        $message = htmlspecialchars($message, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        // Break long words that could break layout (this adds characters but is for display)
        $message = $this->breakLongWords($message);
        
        return $message;
    }
    
    /**
     * Filter URLs, keeping only whitelisted domains
     */
    protected function filterUrls(string $message): string
    {
        // Match URLs including those without protocol
        $urlPattern = '/(?:https?:\/\/|www\.)(?:[a-zA-Z0-9-]+\.)+[a-zA-Z]{2,}(?:\/[^\s]*)?|(?:[a-zA-Z0-9-]+\.)+(?:com|org|net|edu|gov|io|co|uk|de|fr|jp|cn|in|br|au|ca|ru|ch|se|no|dk|fi|nl|be|at|pl|es|it|pt|gr|tr|ae|sg|hk|my|th|id|ph|vn|kr|tw|mx|ar|cl|pe|co|za)(?:\/[^\s]*)?/i';
        
        return preg_replace_callback($urlPattern, function($matches) {
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
        $words = explode(' ', $message);
        $processedWords = [];
        
        foreach ($words as $word) {
            // If word is longer than max length, insert zero-width spaces for wrapping
            if (mb_strlen($word) > $this->maxWordLength) {
                // Insert zero-width space every N characters
                $word = implode('&#8203;', mb_str_split($word, $this->maxWordLength));
            }
            $processedWords[] = $word;
        }
        
        return implode(' ', $processedWords);
    }
    
    /**
     * Check if message contains only allowed characters
     */
    public function hasDisallowedCharacters(string $message): bool
    {
        // Allow alphanumeric, common punctuation, spaces, and common Unicode ranges
        // This pattern allows most legitimate chat but blocks control characters
        $allowedPattern = '/^[\p{L}\p{N}\p{P}\p{S}\p{Zs}\p{Emoji}]+$/u';
        
        return !preg_match($allowedPattern, $message);
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