<?php

namespace Tests\Unit\Support;

use App\Support\Markdown;
use PHPUnit\Framework\TestCase;

/**
 * Session abstracts arrive from pretalx as markdown and are shown with v-html, so what
 * comes out of here has to be both rendered and safe.
 */
class MarkdownTest extends TestCase
{
    public function test_it_renders_the_emphasis_a_con_abstract_actually_uses(): void
    {
        $html = Markdown::render('**_WE ARE BACK!!!_** Four themed rounds, no mercy.');

        $this->assertStringContainsString('<strong><em>WE ARE BACK!!!</em></strong>', $html);
    }

    public function test_single_newlines_become_line_breaks(): void
    {
        // Abstracts are written as prose with plain newlines; collapsing them into one
        // paragraph would run sentences together.
        $html = Markdown::render("Round one\nRound two");

        $this->assertStringContainsString('<br />', $html);
    }

    public function test_lists_and_links_survive(): void
    {
        $html = Markdown::render("- one\n- two\n\n[Rules](https://example.test/rules)");

        $this->assertStringContainsString('<li>one</li>', $html);
        $this->assertStringContainsString('<a href="https://example.test/rules">Rules</a>', $html);
    }

    public function test_raw_html_is_stripped_rather_than_passed_through(): void
    {
        // The HTML block is dropped whole, markdown around it still renders.
        $html = Markdown::render("<script>alert(1)</script>\n<img src=x onerror=alert(1)>\n\nSee you **there**.");

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('onerror', $html);
        $this->assertStringContainsString('<strong>there</strong>', $html);
    }

    public function test_unsafe_link_schemes_are_dropped(): void
    {
        $html = Markdown::render('[click](javascript:alert(1))');

        $this->assertStringNotContainsString('javascript:', $html);
    }

    public function test_empty_input_renders_nothing(): void
    {
        $this->assertNull(Markdown::render(null));
        $this->assertNull(Markdown::render('   '));
    }
}
