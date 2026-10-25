<?php

namespace Atannex\Ecosystem\Tests\Feature;

use Atannex\Ecosystem\Facades\Search;
use Atannex\Ecosystem\Search\SearchOptions;
use Atannex\Ecosystem\Tests\Fixtures\Comment;
use Atannex\Ecosystem\Tests\Fixtures\Post;
use Atannex\Ecosystem\Tests\Fixtures\Region;
use Atannex\Ecosystem\Tests\Fixtures\Tag;
use Atannex\Ecosystem\Tests\Fixtures\User;
use Atannex\Ecosystem\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SearchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Post::$override = null;
    }

    private function titles(string $query): array
    {
        return Post::search($query)->pluck('title')->all();
    }

    private function makePost(array $attributes = []): Post
    {
        return Post::create($attributes + ['title' => 'Untitled']);
    }

    public function test_results_are_ranked_by_match_quality_and_weight(): void
    {
        $this->makePost(['title' => 'Notes', 'body' => 'about the dance festival']);   // body, contains: 2
        $this->makePost(['title' => 'Village dance']);                                 // title, contains: 10
        $this->makePost(['title' => 'Dance groups of Nweh']);                          // title, prefix: 20
        $this->makePost(['title' => 'dance']);                                         // title, exact: 30

        $this->assertSame(['dance', 'Dance groups of Nweh', 'Village dance', 'Notes'], $this->titles('dance'));
    }

    public function test_scores_are_exposed(): void
    {
        $this->makePost(['title' => 'dance']);

        $this->assertEquals(30, Post::search('dance')->first()->search_score);
    }

    public function test_every_word_must_match_somewhere(): void
    {
        $user = User::create(['name' => 'Atanga Collins']);
        $this->makePost(['title' => 'Fontem history', 'user_id' => $user->id]);
        $this->makePost(['title' => 'Fontem market']);

        $this->assertSame(['Fontem history'], $this->titles('fontem collins'));
    }

    public function test_match_any_returns_records_matching_one_term(): void
    {
        Post::$override = SearchOptions::create()->field('title')->matchAny();
        $this->makePost(['title' => 'Fontem']);
        $this->makePost(['title' => 'Menji']);
        $this->makePost(['title' => 'Alou']);

        $this->assertEqualsCanonicalizing(['Fontem', 'Menji'], $this->titles('fontem menji'));
    }

    public function test_relations_are_searched_at_any_depth(): void
    {
        $division = Region::create(['name' => 'Lebialem']);
        $village = Region::create(['name' => 'Bechati', 'parent_id' => $division->id]);
        $user = User::create(['name' => 'Someone', 'region_id' => $village->id]);
        $this->makePost(['title' => 'Village news', 'user_id' => $user->id]);
        $this->makePost(['title' => 'Elsewhere']);

        // posts → user → region → parent region (self relation)
        $this->assertSame(['Village news'], $this->titles('lebialem'));
    }

    public function test_many_to_many_relations(): void
    {
        $post = $this->makePost(['title' => 'Annual report']);
        $post->tags()->attach(Tag::create(['name' => 'Governance'])->id);
        $this->makePost(['title' => 'Other']);

        $this->assertSame(['Annual report'], $this->titles('governance'));
    }

    public function test_json_paths(): void
    {
        $this->makePost(['title' => 'Trip', 'metadata' => ['city' => 'Dschang']]);
        $this->makePost(['title' => 'Stay', 'metadata' => ['city' => 'Buea']]);

        $this->assertSame(['Trip'], $this->titles('dschang'));
        $this->assertSame(['Stay'], $this->titles('city:buea'));
    }

    public function test_soft_deleted_related_records_are_ignored(): void
    {
        $post = $this->makePost(['title' => 'Discussion']);
        Comment::create(['post_id' => $post->id, 'body' => 'spam link'])->delete();

        $this->assertSame([], $this->titles('spam'));
    }

    public function test_phrases_match_words_together(): void
    {
        $this->makePost(['title' => 'The traditional marriage rites']);
        $this->makePost(['title' => 'Marriage, the traditional way']);

        $this->assertSame(['The traditional marriage rites'], $this->titles('"traditional marriage"'));
        $this->assertCount(2, $this->titles('traditional marriage'));
    }

    public function test_excluded_terms(): void
    {
        $this->makePost(['title' => 'Fontem dance']);
        $this->makePost(['title' => 'Fontem market']);

        $this->assertSame(['Fontem market'], $this->titles('fontem -dance'));
        $this->assertSame(['Fontem market'], $this->titles('-dance'));
    }

    public function test_prefix_terms(): void
    {
        $this->makePost(['title' => 'The Bangwa people']);
        $this->makePost(['title' => 'Bangwa kingdom']);
        $this->makePost(['title' => 'Umbang river']);

        $options = SearchOptions::create()->field('title');

        // Any word starting with "bang"; the value starting with it ranks first.
        $this->assertSame(['Bangwa kingdom', 'The Bangwa people'], Post::search('bang*', $options)->pluck('title')->all());
        $this->assertSame(['Bangwa kingdom'], Post::search('bang*', SearchOptions::create()->prefix('title'))->pluck('title')->all());
    }

    public function test_field_terms_search_only_that_field(): void
    {
        $collins = User::create(['name' => 'Collins']);
        $this->makePost(['title' => 'By Collins', 'user_id' => $collins->id]);
        $this->makePost(['title' => 'About Collins']);

        $this->assertSame(['By Collins'], $this->titles('author:collins'));
        $this->assertSame(['About Collins'], $this->titles('-author:collins collins'));
    }

    public function test_unknown_fields_are_plain_text(): void
    {
        $this->makePost(['title' => 'Meeting at 10:30']);

        $this->assertSame(['Meeting at 10:30'], $this->titles('10:30'));
    }

    public function test_comparisons_and_ranges(): void
    {
        $this->makePost(['title' => 'Popular', 'views' => 500, 'published_at' => '2026-03-31 18:00:00']);
        $this->makePost(['title' => 'Quiet', 'views' => 5, 'published_at' => '2026-05-01 08:00:00']);

        $this->assertSame(['Popular'], $this->titles('views:>100'));
        $this->assertSame(['Quiet'], $this->titles('views:<=5'));
        $this->assertSame(['Quiet'], $this->titles('-views:>100'));
        $this->assertSame(['Popular'], $this->titles('date:2026-01-01..2026-03-31'), 'the last day is included');
    }

    public function test_comparisons_through_relations(): void
    {
        $this->makePost(['title' => 'Senior', 'user_id' => User::create(['name' => 'A', 'age' => 70])->id]);
        $this->makePost(['title' => 'Junior', 'user_id' => User::create(['name' => 'B', 'age' => 20])->id]);

        $this->assertSame(['Senior'], $this->titles('age:>=60'));
        $this->assertEqualsCanonicalizing(['Junior', 'Anonymous'], (function () {
            $this->makePost(['title' => 'Anonymous']);

            return $this->titles('-age:>=60');
        })(), 'excluding keeps records whose value is missing');
    }

    public function test_filters_combine_with_text(): void
    {
        $this->makePost(['title' => 'Dance', 'views' => 500]);
        $this->makePost(['title' => 'Dance', 'views' => 1]);

        $this->assertSame(500, Post::search('dance views:>100')->sole()->views);
    }

    public function test_exact_fields_match_the_whole_value_ignoring_case(): void
    {
        $this->makePost(['title' => 'Coded', 'code' => 'INV-2026-0042']);

        $this->assertSame(['Coded'], $this->titles('inv-2026-0042'));
        $this->assertSame([], Post::search('2026', SearchOptions::create()->exact('code'))->pluck('title')->all());
    }

    public function test_like_wildcards_in_input_are_literal(): void
    {
        $this->makePost(['title' => 'Growth of 100%']);
        $this->makePost(['title' => 'Growth of 1000']);
        $this->makePost(['title' => 'snake_case']);
        $this->makePost(['title' => 'snakeXcase']);

        $this->assertSame(['Growth of 100%'], $this->titles('100%'));
        $this->assertSame(['snake_case'], $this->titles('snake_case'));
    }

    public function test_empty_or_too_short_input_leaves_the_query_alone(): void
    {
        $this->makePost(['title' => 'One']);
        $this->makePost(['title' => 'Two']);

        $this->assertCount(2, $this->titles(''));
        $this->assertCount(2, $this->titles('  a  '));
        $this->assertNull(Post::search('')->first()->search_score);
    }

    public function test_it_composes_with_scopes_eager_loading_and_pagination(): void
    {
        foreach (range(1, 7) as $i) {
            $this->makePost(['title' => "Dance {$i}", 'views' => $i]);
        }

        $page = Post::search('dance')->with('user')->where('views', '>', 2)->paginate(2);

        $this->assertSame(5, $page->total());
        $this->assertCount(2, $page->items());
    }

    public function test_custom_order_can_replace_ranking(): void
    {
        $this->makePost(['title' => 'dance', 'views' => 1]);
        $this->makePost(['title' => 'village dance', 'views' => 9]);

        $options = SearchOptions::create()->field('title')->withoutRanking();

        $this->assertSame(['village dance', 'dance'], Post::search('dance', $options)->orderByDesc('views')->pluck('title')->all());
    }

    public function test_auto_columns_search_text_columns_but_not_secrets(): void
    {
        User::create(['name' => 'Visible', 'password' => 'hunter2', 'api_token' => 'tok-secret']);

        $options = SearchOptions::create()->autoColumns();

        $this->assertSame(1, User::search('visible', $options)->count());
        $this->assertSame(0, User::search('hunter2', $options)->count());
        $this->assertSame(0, User::search('tok-secret', $options)->count());
    }

    public function test_models_without_options_search_all_text_columns(): void
    {
        Region::create(['name' => 'Lebialem']);

        $this->assertSame(1, Region::search('lebia')->count());
    }

    public function test_full_text(): void
    {
        // MySQL/PostgreSQL use the index; SQLite falls back to LIKE.
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            Schema::table('posts', fn (Blueprint $table) => $table->fullText(['title', 'body']));
        }

        $this->makePost(['title' => 'Kings', 'body' => 'The royal palace of Fontem']);
        $this->makePost(['title' => 'Queens', 'body' => 'Market day']);

        $options = SearchOptions::create()->fullText(['title', 'body'], 5);

        $this->assertSame(['Kings'], Post::search('palace', $options)->pluck('title')->all());
        $this->assertSame(['Kings'], Post::search('pala*', $options)->pluck('title')->all());
        $this->assertSame(['Kings'], Post::search('"royal palace"', $options)->pluck('title')->all());

        if (DB::getDriverName() !== 'sqlite') {
            $this->assertSame(['Kings'], Post::search('+palace)(~', $options)->pluck('title')->all(), 'full-text operators in input are not injected');
        }
    }

    public function test_options_without_fields_match_nothing(): void
    {
        $this->makePost(['title' => 'Anything']);

        $this->assertSame(0, Post::search('anything', SearchOptions::create())->count());
    }

    public function test_any_query_can_be_searched_without_the_trait(): void
    {
        Tag::create(['name' => 'Culture']);
        Tag::create(['name' => 'Sport']);

        $this->assertSame(['Culture'], Search::apply(Tag::query(), 'cult', SearchOptions::create()->field('name'))->pluck('name')->all());
    }
}
