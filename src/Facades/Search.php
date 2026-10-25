<?php

declare(strict_types=1);

namespace Atannex\Ecosystem\Facades;

use Atannex\Ecosystem\Search\GlobalSearch;
use Atannex\Ecosystem\Search\ParsedQuery;
use Atannex\Ecosystem\Search\SearchManager;
use Atannex\Ecosystem\Search\SearchOptions;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\HtmlString;

/**
 * @method static GlobalSearch across(array $models)
 * @method static Builder apply(Builder $query, string|ParsedQuery|null $terms, SearchOptions $options)
 * @method static ParsedQuery parse(?string $input)
 * @method static string|null suggest(string|ParsedQuery|null $query, iterable $vocabulary)
 * @method static array vocabulary(iterable $texts, int $minLength = 3)
 * @method static void resolveColumnsUsing(?Closure $resolver)
 * @method static string column(Model $model, string $column)
 * @method static HtmlString highlight(?string $text, string|ParsedQuery|null $query)
 * @method static HtmlString excerpt(?string $text, string|ParsedQuery|null $query, int $length = 160)
 *
 * @see SearchManager
 */
class Search extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return SearchManager::class;
    }
}
