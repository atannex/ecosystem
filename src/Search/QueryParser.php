<?php

declare(strict_types=1);

namespace Atannex\Ecosystem\Search;

/**
 * Turns visitor input into terms.
 *
 *     lebialem chiefs                 both words, anywhere
 *     "traditional marriage"          the exact phrase
 *     fontem -dance                   "fontem", without "dance"
 *     bang*                           words starting with "bang"
 *     author:collins                  only in the "author" field
 *     views:>100  views:<=5           comparisons
 *     created:2026-01-01..2026-03-31  ranges (inclusive)
 *
 * Input is trimmed to a maximum length and number of terms; short words are
 * dropped (a quoted or field term is always kept). Stopwords ("the", "of")
 * are dropped too, unless the query is made of nothing else ("the who").
 */
final class QueryParser
{
    private const TOKEN = '/(-)?(?:([\p{L}\p{N}_.\-]+):)?(?:"([^"]*)"?|(\S+))/u';

    /** @var array<string, true> */
    private readonly array $stopwords;

    /**
     * @param  array<int, string>  $stopwords
     */
    public function __construct(
        private readonly int $maxLength = 200,
        private readonly int $maxTerms = 8,
        private readonly int $minTermLength = 2,
        array $stopwords = [],
    ) {
        $this->stopwords = array_fill_keys(array_map(mb_strtolower(...), $stopwords), true);
    }

    public function parse(?string $input): ParsedQuery
    {
        $original = trim(mb_substr((string) $input, 0, $this->maxLength));

        preg_match_all(self::TOKEN, $original, $matches, PREG_SET_ORDER);

        $terms = array_values(array_filter(array_map($this->term(...), $matches)));

        return new ParsedQuery($original, array_slice($this->unique($this->withoutStopwords($terms)), 0, $this->maxTerms));
    }

    /**
     * @param  array<int, Term>  $terms
     * @return array<int, Term>
     */
    private function withoutStopwords(array $terms): array
    {
        $isStopword = fn (Term $term) => $term->field === null && ! $term->phrase && ! $term->prefix
            && isset($this->stopwords[mb_strtolower($term->value)]);

        $meaningful = array_values(array_filter($terms, fn (Term $term) => ! $isStopword($term)));

        // Only stopwords: search for them rather than for nothing.
        $positive = array_filter($meaningful, fn (Term $term) => ! $term->negated);

        return $positive === [] ? $terms : $meaningful;
    }

    /**
     * @param  array<int, string>  $match
     */
    private function term(array $match): ?Term
    {
        $negated = ($match[1] ?? '') === '-';
        $field = ($match[2] ?? '') !== '' ? $match[2] : null;
        $phrase = isset($match[3]) && $match[3] !== '' && ($match[4] ?? '') === '';
        $value = trim($phrase ? $match[3] : ($match[4] ?? ''));

        // A lone "-" or "field:" with nothing after it.
        if ($value === '' || $value === '-') {
            return null;
        }

        if ($field !== null && ! $phrase) {
            if (preg_match('/^(>=|<=|>|<)(.+)$/u', $value, $m)) {
                return new Term($m[2], negated: $negated, field: $field, operator: $m[1]);
            }

            if (preg_match('/^(.+)\.\.(.+)$/u', $value, $m)) {
                return new Term($m[1], negated: $negated, field: $field, operator: '..', until: $m[2]);
            }
        }

        $prefix = ! $phrase && str_ends_with($value, '*');
        $value = $prefix ? rtrim($value, '*') : $value;

        if ($value === '' || (! $phrase && $field === null && mb_strlen($value) < $this->minTermLength)) {
            return null;
        }

        return new Term($value, $phrase, $negated, $prefix, $field);
    }

    /**
     * @param  array<int, Term>  $terms
     * @return array<int, Term>
     */
    private function unique(array $terms): array
    {
        $seen = [];

        return array_values(array_filter($terms, function (Term $term) use (&$seen): bool {
            $key = mb_strtolower(serialize([$term->value, $term->negated, $term->prefix, $term->field, $term->operator, $term->until]));

            return ! isset($seen[$key]) && ($seen[$key] = true);
        }));
    }
}
