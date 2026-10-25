<?php

namespace Atannex\Ecosystem\Tests\Feature;

use Atannex\Ecosystem\Facades\Search;
use Atannex\Ecosystem\Search\SearchHit;
use Atannex\Ecosystem\Tests\Fixtures\Post;
use Atannex\Ecosystem\Tests\Fixtures\Tag;
use Atannex\Ecosystem\Tests\Fixtures\User;
use Atannex\Ecosystem\Tests\TestCase;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

class GlobalSearchTest extends TestCase
{
    public function test_results_from_several_models_are_merged_by_score(): void
    {
        User::create(['name' => 'Fontem']);                      // name exact: 15
        Post::create(['title' => 'Fontem', 'views' => 3]);       // title exact: 30
        Post::create(['title' => 'Hidden Fontem', 'views' => 0]);

        $hits = Search::across([
            Post::class => fn (Builder $q) => $q->where('views', '>', 0),
            User::class,
        ])->query('fontem')->get();

        $this->assertSame(['Fontem', 'Fontem'], $hits->pluck('title')->all());
        $this->assertSame(['Post', 'People'], $hits->pluck('type')->all());
        $this->assertSame('/posts/1', $hits->first()->url);
        $this->assertSame('/people/1', $hits->last()->url);
    }

    public function test_results_can_be_grouped_and_limited_per_type(): void
    {
        foreach (range(1, 4) as $i) {
            Post::create(['title' => "Dance {$i}"]);
        }

        $groups = Search::across([Post::class, User::class])->query('dance')->perType(2)->grouped();

        $this->assertSame(['Post'], $groups->keys()->all());
        $this->assertCount(2, $groups['Post']);
    }

    public function test_hits_serialize_for_apis(): void
    {
        Post::create(['title' => 'Dance', 'body' => 'Body text']);

        $hit = Search::across([Post::class])->query('dance')->get()->first();

        $this->assertInstanceOf(SearchHit::class, $hit);
        $this->assertSame(['type' => 'Post', 'id' => 1, 'title' => 'Dance', 'description' => 'Body text', 'url' => '/posts/1', 'score' => 30.0], $hit->toArray());
    }

    public function test_empty_queries_return_nothing(): void
    {
        Post::create(['title' => 'Dance']);

        $this->assertTrue(Search::across([Post::class])->query('')->get()->isEmpty());
    }

    public function test_only_searchable_models_are_accepted(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Search::across([Tag::class]);
    }

    public function test_console_command(): void
    {
        Post::create(['title' => 'Dance festival']);

        $this->artisan('ecosystem:search', ['query' => 'dance', '--model' => [Post::class]])
            ->expectsOutputToContain('Dance festival')
            ->assertSuccessful();
    }
}
