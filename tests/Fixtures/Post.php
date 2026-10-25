<?php

namespace Atannex\Ecosystem\Tests\Fixtures;

use Atannex\Ecosystem\Search\Searchable;
use Atannex\Ecosystem\Search\SearchOptions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Post extends Model
{
    use Searchable;
    use SoftDeletes;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['metadata' => 'array', 'published_at' => 'datetime'];

    public static ?SearchOptions $override = null;

    public function searchOptions(): SearchOptions
    {
        return static::$override ?? SearchOptions::create()
            ->field('title', 10)
            ->field('body', 2)
            ->exact('code', 8)
            ->field('user.name', 4, as: 'author')
            ->field('tags.name', 5, as: 'tag')
            ->field('user.region.parent.name', 1, as: 'area')
            ->field('metadata->city', 2, as: 'city')
            ->field('comments.body', 1)
            ->filter('views')
            ->filter('published_at', as: 'date')
            ->filter('user.age', as: 'age')
            ->title('title')
            ->description('body')
            ->url(fn (self $post) => '/posts/'.$post->id);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }
}
