<?php

namespace Atannex\Ecosystem\Tests\Fixtures;

use Atannex\Ecosystem\Search\Searchable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Region extends Model
{
    use Searchable;

    public $timestamps = false;

    protected $guarded = [];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }
}
