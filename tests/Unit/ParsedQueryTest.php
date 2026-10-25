<?php

namespace Atannex\Ecosystem\Tests\Unit;

use Atannex\Ecosystem\Facades\Search;
use Atannex\Ecosystem\Tests\TestCase;

class ParsedQueryTest extends TestCase
{
    public function test_words_fields_and_rewriting(): void
    {
        $query = Search::parse('Royal "palace gate" fon* -market tag:culture date:>2025');

        $this->assertSame(['royal'], $query->words());
        $this->assertSame('Royal "palace gate" fon* -market', $query->withoutFields()->toQuery());
        $this->assertSame('King "palace gate" fon* -market tag:culture date:>2025', $query->rewrite(['royal' => 'King']));
    }

    public function test_has_text_and_rankable(): void
    {
        $this->assertTrue(Search::parse('palace')->hasText());
        $this->assertFalse(Search::parse('-market')->hasText());
        $this->assertTrue(Search::parse('date:>2025')->hasText());
        $this->assertFalse(Search::parse('date:>2025')->isRankable());
        $this->assertTrue(Search::parse('palace date:>2025')->isRankable());
    }
}
