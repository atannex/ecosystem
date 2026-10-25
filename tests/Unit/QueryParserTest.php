<?php

namespace Atannex\Ecosystem\Tests\Unit;

use Atannex\Ecosystem\Search\QueryParser;
use Atannex\Ecosystem\Search\Term;
use PHPUnit\Framework\TestCase;

class QueryParserTest extends TestCase
{
    private function terms(string $input, ?QueryParser $parser = null): array
    {
        return array_map(
            fn (Term $t) => array_filter([
                'value' => $t->value,
                'phrase' => $t->phrase ?: null,
                'negated' => $t->negated ?: null,
                'prefix' => $t->prefix ?: null,
                'field' => $t->field,
                'operator' => $t->operator,
                'until' => $t->until,
            ]),
            ($parser ?? new QueryParser)->parse($input)->terms,
        );
    }

    public function test_words_phrases_exclusions_and_prefixes(): void
    {
        $this->assertSame([
            ['value' => 'lebialem'],
            ['value' => 'traditional marriage', 'phrase' => true],
            ['value' => 'dance', 'negated' => true],
            ['value' => 'bang', 'prefix' => true],
        ], $this->terms('lebialem "traditional marriage" -dance bang*'));
    }

    public function test_field_filters_comparisons_and_ranges(): void
    {
        $this->assertSame([
            ['value' => 'collins', 'field' => 'author'],
            ['value' => 'Fon Asong', 'phrase' => true, 'field' => 'author'],
            ['value' => '100', 'field' => 'views', 'operator' => '>'],
            ['value' => '5', 'negated' => true, 'field' => 'views', 'operator' => '<='],
            ['value' => '2026-01-01', 'field' => 'date', 'operator' => '..', 'until' => '2026-03-31'],
        ], $this->terms('author:collins author:"Fon Asong" views:>100 -views:<=5 date:2026-01-01..2026-03-31'));
    }

    public function test_short_words_are_dropped_unless_quoted_or_filtered(): void
    {
        $this->assertSame([['value' => 'a', 'phrase' => true], ['value' => 'a', 'field' => 'grade']], $this->terms('a "a" grade:a'));
    }

    public function test_limits_and_duplicates(): void
    {
        $parser = new QueryParser(maxLength: 20, maxTerms: 2);

        $this->assertSame([['value' => 'one'], ['value' => 'two']], $this->terms('one two three four', $parser));
        $this->assertSame([['value' => 'abcdefghijklmnopqrst']], $this->terms('abcdefghijklmnopqrstuvwxyz', $parser));
        $this->assertSame([['value' => 'Dance']], $this->terms('Dance dance DANCE'));
    }

    public function test_unbalanced_quotes_and_lone_symbols(): void
    {
        $this->assertSame([['value' => 'open phrase', 'phrase' => true]], $this->terms('"open phrase'));
        $this->assertSame([], $this->terms(' - "" -'));
    }

    public function test_hyphenated_words_and_unicode(): void
    {
        $this->assertSame([['value' => 'co-op'], ['value' => 'Ébène']], $this->terms('co-op Ébène'));
    }

    public function test_stopwords_are_dropped_unless_they_are_all_there_is(): void
    {
        $parser = new QueryParser(stopwords: ['the', 'of', 'De']);

        $this->assertSame([['value' => 'kings'], ['value' => 'the who', 'phrase' => true]], $this->terms('the kings of "the who"', $parser));
        $this->assertSame([['value' => 'the'], ['value' => 'of']], $this->terms('the of', $parser));
        $this->assertSame([['value' => 'de'], ['value' => 'xy', 'negated' => true]], $this->terms('de -xy', $parser));
    }

    public function test_the_term_cap_applies_after_stopwords(): void
    {
        $parser = new QueryParser(maxTerms: 2, stopwords: ['the']);

        $this->assertSame([['value' => 'one'], ['value' => 'two']], $this->terms('the the one the two three', $parser));
    }

    public function test_queries_can_be_rebuilt_from_their_terms(): void
    {
        $query = (new QueryParser)->parse('lebialem  "royal palace" -dance bang* author:collins views:>100 date:2026-01-01..2026-02-01');

        $this->assertSame('lebialem "royal palace" -dance bang* author:collins views:>100 date:2026-01-01..2026-02-01', $query->toQuery());
    }
}
