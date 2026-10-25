<?php

namespace Atannex\Ecosystem\Tests\Feature;

use Atannex\Ecosystem\Facades\Search;
use Atannex\Ecosystem\Search\ParsedQuery;
use Atannex\Ecosystem\Search\SearchOptions;
use Atannex\Ecosystem\Tests\Fixtures\Article;
use Atannex\Ecosystem\Tests\Fixtures\Post;
use Atannex\Ecosystem\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class ColumnsAndBoostsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Post::$override = null;
        Article::$override = null;
        Search::resolveColumnsUsing(null);

        Schema::dropIfExists('articles');
        Schema::create('articles', function (Blueprint $table) {
            $table->id();
            $table->json('title');
            $table->string('code')->nullable();
        });

        config(['app.fallback_locale' => 'en']);
    }

    public function test_translatable_columns_are_searched_in_the_current_locale_with_a_fallback(): void
    {
        Article::create(['title' => ['en' => 'Royal Palace', 'fr' => 'Palais royal']]);
        Article::create(['title' => ['en' => 'Market day']]);

        app()->setLocale('fr');

        $this->assertSame(['Palais royal'], Article::search('palais')->get()->pluck('title.fr')->all());
        // No French title: the English one is searched.
        $this->assertSame(['Market day'], Article::search('MARKET')->get()->pluck('title.en')->all());
        // The other locale and the JSON keys themselves are not searched.
        $this->assertCount(0, Article::search('palace')->get());
        $this->assertCount(0, Article::search('fr')->get());
    }

    public function test_translatable_columns_are_ranked_like_plain_ones(): void
    {
        app()->setLocale('en');
        Article::create(['title' => ['en' => 'Village dance']]);
        Article::create(['title' => ['en' => 'Dance']]);

        $this->assertSame(['Dance', 'Village dance'], Article::search('dance')->get()->pluck('title.en')->all());
        $this->assertEquals(30, Article::search('dance')->first()->search_score);
    }

    public function test_a_resolver_supplies_sql_for_computed_columns(): void
    {
        Article::create(['title' => ['en' => 'x'], 'code' => 'ab-12']);

        Search::resolveColumnsUsing(fn ($model, string $column) => $column === 'code'
            ? "REPLACE({$model->qualifyColumn('code')}, '-', '')"
            : null);

        $this->assertCount(1, Article::search('ab12')->get());
        $this->assertStringContainsString('REPLACE', Search::column(new Article, 'code'));
    }

    public function test_boosts_add_to_the_score(): void
    {
        $old = Post::create(['title' => 'Dance', 'views' => 1]);
        $popular = Post::create(['title' => 'Dance', 'views' => 500]);

        Post::$override = SearchOptions::create()
            ->field('title', 10)
            ->boost('CASE WHEN posts.views > ? THEN 7 ELSE 0 END', [100])
            ->boost(fn (ParsedQuery $query) => $query->required() === [] ? null : ['?', [1]]);

        $results = Post::search('dance')->get();

        $this->assertSame([$popular->id, $old->id], $results->pluck('id')->all());
        $this->assertEquals(38, $results->first()->search_score);
        $this->assertEquals(31, $results->last()->search_score);
    }

    public function test_phrase_boosts_reward_words_together_and_take_wildcards_literally(): void
    {
        Article::$override = SearchOptions::create()->field('title', 10)->boostPhrase('title', 40);

        Article::create(['title' => ['en' => 'Festival of the village']]);
        Article::create(['title' => ['en' => 'Village festival returns']]);
        Article::create(['title' => ['en' => '100% village festival']]);

        $scores = fn (string $q) => Article::search($q)->get()->mapWithKeys(fn ($a) => [$a->title['en'] => (float) $a->search_score]);
        $boosted = $scores('village festival');
        $returns = $scores('returns')->first();

        Article::$override = SearchOptions::create()->field('title', 10);
        $plain = $scores('village festival');

        $this->assertSame(40.0, $boosted['Village festival returns'] - $plain['Village festival returns'], 'the words together');
        $this->assertSame(0.0, $boosted['Festival of the village'] - $plain['Festival of the village'], 'both words, not together');
        $this->assertSame($scores('returns')->first(), $returns, 'one word: no phrase boost');

        // "%" in the query is not a wildcard.
        Article::$override = SearchOptions::create()->field('title', 10)->boostPhrase('title', 40);
        $this->assertSame(['100% village festival'], Article::search('"100%" village festival')->get()->map(fn ($a) => $a->title['en'])->all());
        $this->assertCount(0, Article::search('1%0')->get());
    }

    public function test_did_you_mean(): void
    {
        $vocabulary = Search::vocabulary(['The Royal Palace of Fontem', 'Lebialem festival', null, 'a an of']);

        $this->assertSame(['the', 'royal', 'palace', 'fontem', 'lebialem', 'festival'], $vocabulary);
        $this->assertSame('royal palace tag:culture', Search::suggest('royl palace tag:culture', $vocabulary));
        $this->assertSame('lebialem festival', Search::suggest('lebiallem festivl', $vocabulary));
        $this->assertNull(Search::suggest('palace', $vocabulary), 'nothing to correct');
        $this->assertNull(Search::suggest('"royl palace" -fontm 2026', $vocabulary), 'phrases, exclusions and numbers are left alone');
        $this->assertNull(Search::suggest('zzzzzz', $vocabulary), 'nothing close enough');
    }

    public function test_html_entities_are_never_split_by_highlighting(): void
    {
        $this->assertSame('<mark>Floods</mark> &amp; roads', (string) Search::highlight('Floods & roads', 'floods amp'));
    }
}
