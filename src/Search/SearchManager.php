<?php

declare(strict_types=1);

namespace Atannex\Ecosystem\Search;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

/**
 * Entry point behind the Search facade.
 */
class SearchManager
{
    public function __construct(
        private readonly SearchEngine $engine,
        private readonly Highlighter $highlighter,
        private readonly int $perType = 5,
    ) {}

    /**
     * Search several models at once (see GlobalSearch).
     *
     * @param  array<int|string, mixed>  $models
     */
    public function across(array $models): GlobalSearch
    {
        return (new GlobalSearch($this->engine, $this->perType))->in($models);
    }

    /**
     * Search any query with ad-hoc options, no trait needed:
     * Search::apply(Order::query(), $q, SearchOptions::create()->exact('number'));
     */
    public function apply(Builder $query, string|ParsedQuery|null $terms, SearchOptions $options): Builder
    {
        return $this->engine->apply($query, $terms, $options);
    }

    /**
     * SQL to search for computed columns (see SearchEngine::resolveColumnsUsing()).
     *
     * @param  (Closure(Model, string): ?string)|null  $resolver
     */
    public function resolveColumnsUsing(?Closure $resolver): void
    {
        $this->engine->resolveColumnsUsing($resolver);
    }

    /**
     * The SQL searched for $column of $model, translatable columns included.
     */
    public function column(Model $model, string $column): string
    {
        return $this->engine->column($model, $column);
    }

    /**
     * "Did you mean": the query with unknown words corrected from $vocabulary, or null.
     *
     * @param  iterable<int, string>  $vocabulary
     */
    public function suggest(string|ParsedQuery|null $query, iterable $vocabulary): ?string
    {
        return (new Suggester)->suggest($query instanceof ParsedQuery ? $query : $this->parse($query), $vocabulary);
    }

    /**
     * Distinct lowercase words in some texts, for suggest().
     *
     * @param  iterable<int, string|null>  $texts
     * @return list<string>
     */
    public function vocabulary(iterable $texts, int $minLength = 3): array
    {
        return (new Suggester)->vocabulary($texts, $minLength);
    }

    public function parse(?string $input): ParsedQuery
    {
        return $this->engine->parse($input);
    }

    public function highlight(?string $text, string|ParsedQuery|null $query): HtmlString
    {
        return $this->highlighter->highlight($text, $this->terms($query));
    }

    public function excerpt(?string $text, string|ParsedQuery|null $query, int $length = 160): HtmlString
    {
        return $this->highlighter->excerpt($text, $this->terms($query), $length);
    }

    /**
     * @return array<int, string>
     */
    private function terms(string|ParsedQuery|null $query): array
    {
        return ($query instanceof ParsedQuery ? $query : $this->parse($query))->highlightable();
    }
}
