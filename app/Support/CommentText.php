<?php

namespace App\Support;

/**
 * What a comment body is allowed to be.
 *
 * Plain text on the way in and plain text on the way out: the client renders it
 * into a text node and never into markup, so nothing here has to strip tags or
 * escape anything. What it does do is take the shapes that break a page away -
 * invisible characters, bidi overrides, a wall of blank lines - and put a cap on
 * the length, because the box is a comment box and not a blog.
 */
final class CommentText
{
    public const MAX_LENGTH = 1500;

    public static function normalise(string $body): string
    {
        // C0/C1 controls except newline, zero-width marks and bidi overrides.
        $body = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $body);
        $body = preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{206F}\x{FEFF}]/u', '', $body);

        $body = str_replace(["\r\n", "\r", "\t"], ["\n", "\n", ' '], $body);
        $body = preg_replace('/\n{3,}/', "\n\n", $body);
        $body = preg_replace('/[ ]{4,}/', '   ', $body);
        $body = trim($body);

        return mb_strlen($body) > self::MAX_LENGTH
            ? mb_substr($body, 0, self::MAX_LENGTH)
            : $body;
    }
}
