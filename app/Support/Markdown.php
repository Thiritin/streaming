<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Markdown for text an operator or the programme team wrote, rendered for display.
 *
 * Session abstracts come out of pretalx as markdown - bold, italics, the occasional list -
 * and were previously printed with the asterisks still in them. Everything rendered here
 * is treated as untrusted: raw HTML in the source is stripped rather than passed through,
 * and unsafe link schemes (javascript:, data:) are dropped, so the output is safe to put
 * in a v-html.
 */
final class Markdown
{
    /**
     * @var array<string, mixed>
     */
    private const OPTIONS = [
        'html_input' => 'strip',
        'allow_unsafe_links' => false,
        // A con abstract is written as prose with single newlines; without this every
        // line break would collapse into one wall of text.
        'renderer' => ['soft_break' => "<br />\n"],
        // Deeply nested quoting is not something an abstract needs, and it is the usual
        // way to make the parser do too much work.
        'max_nesting_level' => 10,
    ];

    public static function render(?string $text): ?string
    {
        $text = is_string($text) ? trim($text) : '';

        if ($text === '') {
            return null;
        }

        return trim(Str::markdown($text, self::OPTIONS));
    }
}
