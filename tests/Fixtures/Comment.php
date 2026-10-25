<?php

namespace Atannex\Ecosystem\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Comment extends Model
{
    use SoftDeletes;

    public $timestamps = false;

    protected $guarded = [];
}
