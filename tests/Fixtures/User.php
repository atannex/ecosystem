<?php

namespace Atannex\Ecosystem\Tests\Fixtures;

use Atannex\Ecosystem\Search\Searchable;
use Atannex\Ecosystem\Search\SearchOptions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class User extends Model
{
    use Searchable;

    public $timestamps = false;

    protected $guarded = [];

    protected $hidden = ['api_token'];

    public function searchOptions(): SearchOptions
    {
        return SearchOptions::create()
            ->field('name', 5)
            ->exact('email', 5)
            ->label('People')
            ->url(fn (self $user) => '/people/'.$user->id);
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }
}
