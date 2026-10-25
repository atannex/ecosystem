<?php

declare(strict_types=1);

namespace Atannex\Ecosystem\Search;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Makes an Eloquent model searchable.
 *
 *     class Post extends Model
 *     {
 *         use Searchable;
 *
 *         public function searchOptions(): SearchOptions
 *         {
 *             return SearchOptions::create()
 *                 ->field('title', 10)
 *                 ->field('author.user.name', 4, as: 'author')
 *                 ->field('tags.name', 5, as: 'tag');
 *         }
 *     }
 *
 *     Post::search('"traditional dance" -wedding author:collins')->paginate();
 *
 * The scope returns an ordinary query: chain scopes, eager loads, filters and
 * pagination as usual. Ranked results carry a "search_score" attribute.
 *
 * @mixin Model
 */
trait Searchable
{
    /**
     * Override to configure. By default every text column of the table.
     */
    public function searchOptions(): SearchOptions
    {
        return SearchOptions::create()->autoColumns();
    }

    /**
     * @param  string|ParsedQuery|null  $terms  Visitor input; empty input leaves the query unchanged.
     * @param  SearchOptions|null  $options  Override the model's options for this query.
     */
    public function scopeSearch(Builder $query, string|ParsedQuery|null $terms, ?SearchOptions $options = null): Builder
    {
        return app(SearchEngine::class)->apply($query, $terms, $options ?? $this->searchOptions());
    }

    public function toSearchHit(): SearchHit
    {
        $options = $this->searchOptions();

        return new SearchHit(
            model: $this,
            type: $options->resolveLabel($this),
            title: $options->resolveTitle($this),
            description: $options->resolveDescription($this),
            url: $options->resolveUrl($this),
            score: (float) ($this->getAttribute('search_score') ?? 0),
        );
    }
}
