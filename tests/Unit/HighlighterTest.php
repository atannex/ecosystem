<?php

namespace Atannex\Ecosystem\Tests\Unit;

use Atannex\Ecosystem\Search\Highlighter;
use PHPUnit\Framework\TestCase;

class HighlighterTest extends TestCase
{
    public function test_terms_are_marked_case_insensitively(): void
    {
        $html = (new Highlighter)->highlight('Dance and the dance group', ['dance', 'dance group']);

        $this->assertSame('<mark>Dance</mark> and the <mark>dance group</mark>', (string) $html);
    }

    public function test_text_is_escaped_so_user_markup_is_harmless(): void
    {
        $html = (string) (new Highlighter)->highlight('<script>alert(1)</script> & dance', ['dance', 'script']);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertSame('&lt;<mark>script</mark>&gt;alert(1)&lt;/<mark>script</mark>&gt; &amp; <mark>dance</mark>', $html);
    }

    public function test_excerpts_center_on_the_first_match(): void
    {
        $text = '<p>'.str_repeat('filler words here ', 30).'the royal palace of Fontem stands tall '.str_repeat('more filler ', 30).'</p>';

        $excerpt = (string) (new Highlighter)->excerpt($text, ['palace'], 80);

        $this->assertStringStartsWith('…', $excerpt);
        $this->assertStringEndsWith('…', $excerpt);
        $this->assertStringContainsString('<mark>palace</mark>', $excerpt);
        $this->assertStringNotContainsString('<p>', $excerpt);
    }

    public function test_custom_tag_and_no_terms(): void
    {
        $this->assertSame('<em>x</em>y', (string) (new Highlighter('em'))->highlight('xy', ['x']));
        $this->assertSame('plain', (string) (new Highlighter)->highlight('plain', []));
    }

    public function test_a_class_can_be_added_to_the_tag(): void
    {
        $this->assertSame('<mark class="hit">x</mark>y', (string) (new Highlighter('mark', 'hit'))->highlight('xy', ['x']));
        $this->assertSame('<mark>x</mark>', (string) (new Highlighter('<script>'))->highlight('x', ['x']));
    }

    public function test_terms_never_match_inside_entities(): void
    {
        $this->assertSame('a &amp; &quot;b&quot;', (string) (new Highlighter)->highlight('a & "b"', ['amp', 'quot']));
    }
}
