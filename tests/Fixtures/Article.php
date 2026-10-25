<?php

namespace Atannex\Ecosystem\Tests\Fixtures;

use Atannex\Ecosystem\Search\Searchable;
use Atannex\Ecosystem\Search\SearchOptions;
use Illuminate\Database\Eloquent\Model;

/**
 * A model with translatable columns, shaped like spatie/laravel-translatable:
 * {"en": "…", "fr": "…"} in a JSON column.
 */
class Article extends Model
{
    use Searchable;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['title' => 'array'];

    public array $translatable = ['title'];

    public static ?SearchOptions $override = null;

    public function isTranslatableAttribute(string $key): bool
    {
        return in_array($key, $this->translatable, true);
    }

    public function searchOptions(): SearchOptions
    {
        return static::$override ?? SearchOptions::create()->field('title', 10)->field('code', 1);
    }
}
