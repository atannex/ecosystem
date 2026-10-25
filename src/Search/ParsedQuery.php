<?php

declare(strict_types=1);

namespace Atannex\Ecosystem\Search;

final class ParsedQuery
{
    /**
     * @param  array<int, Term>  $terms
     */
    public function __construct(
        public readonly string $original,
        public readonly array $terms,
    ) {}

    public function isEmpty(): bool
    {
        return $this->terms === [];
    }

    /**
     * Text terms that must match (no filters, no exclusions).
     *
     * @return array<int, Term>
     */
    public function required(): array
    {
        return array_values(array_filter($this->terms, fn (Term $t) => ! $t->negated && $t->field === null));
    }

    /**
     * @return array<int, Term>
     */
    public function excluded(): array
    {
        return array_values(array_filter($this->terms, fn (Term $t) => $t->negated));
    }

    /**
     * @return array<int, Term>
     */
    public function filters(): array
    {
        return array_values(array_filter($this->terms, fn (Term $t) => ! $t->negated && $t->field !== null));
    }

    /**
     * Words and phrases to highlight.
     *
     * @return array<int, string>
     */
    public function highlightable(): array
    {
        return array_values(array_unique(array_map(
            fn (Term $t) => $t->value,
            array_filter($this->terms, fn (Term $t) => ! $t->negated && ! $t->isComparison()),
        )));
    }

    /**
     * Plain words (no phrases, prefixes, fields or exclusions), lowercased:
     * what a spelling suggestion may correct, or a phrase boost may join.
     *
     * @return list<string>
     */
    public function words(): array
    {
        return array_values(array_map(
            fn (Term $t) => mb_strtolower($t->value),
            array_filter($this->terms, self::isWord(...)),
        ));
    }

    /**
     * The same query without field filters ("tag:…", "date:…").
     */
    public function withoutFields(): self
    {
        return new self($this->original, array_values(array_filter($this->terms, fn (Term $t) => $t->field === null)));
    }

    /**
     * Something to find: at least one term that is not an exclusion.
     */
    public function hasText(): bool
    {
        foreach ($this->terms as $term) {
            if (! $term->negated) {
                return true;
            }
        }

        return false;
    }

    /**
     * Text results can be ranked by (comparisons such as date:>… only filter).
     */
    public function isRankable(): bool
    {
        foreach ($this->terms as $term) {
            if (! $term->negated && ! $term->isComparison()) {
                return true;
            }
        }

        return false;
    }

    /**
     * The query in search syntax with some plain words replaced.
     *
     * @param  array<string, string>  $replacements  lowercase word => new word
     */
    public function rewrite(array $replacements): string
    {
        return implode(' ', array_map(
            fn (Term $t) => self::isWord($t) && isset($replacements[mb_strtolower($t->value)])
                ? (new Term($replacements[mb_strtolower($t->value)]))->toQuery()
                : $t->toQuery(),
            $this->terms,
        ));
    }

    /**
     * The terms in search syntax: what was understood, without the noise.
     */
    public function toQuery(): string
    {
        return implode(' ', array_map(fn (Term $t) => $t->toQuery(), $this->terms));
    }

    private static function isWord(Term $term): bool
    {
        return $term->field === null && ! $term->phrase && ! $term->prefix && ! $term->negated;
    }
}
