# atannex/ecosystem

[![Tests](https://github.com/atannex/ecosystem/actions/workflows/tests.yml/badge.svg)](https://github.com/atannex/ecosystem/actions/workflows/tests.yml)
[![PHP](https://img.shields.io/badge/PHP-8.3%2B-777BB4?logo=php&logoColor=white)](composer.json)
[![Laravel](https://img.shields.io/badge/Laravel-11%20%7C%2012-FF2D20?logo=laravel&logoColor=white)](composer.json)
[![License: MIT](https://img.shields.io/badge/License-MIT-2ea44f.svg)](LICENSE)

Deep, ranked search for Laravel Eloquent, running in your own database with no search server to host.

```php
Post::search('"traditional dance" lebialem -wedding author:collins views:>100')->paginate();
```

- **Any depth.** Search through relations as deep as your data goes (`author.user.region.parent.name`), many-to-many relations and JSON paths (`metadata->city`).
- **Multilingual.** Translatable columns (spatie/laravel-translatable) are searched in the current locale, with a fallback.
- **Ranked.** Field weights × match quality: an exact match beats a starts-with match, which beats a match anywhere. One SQL query, no N+1.
- **Visitor syntax.** Phrases, exclusions, prefixes, field filters, comparisons and date ranges.
- **Global search.** Search several models at once, merged by score, ready for a results page or an API.
- **Safe by default.** Input is length- and term-limited, LIKE wildcards are escaped, full-text operators can't be injected, highlighting escapes HTML, and soft-deleted related rows are ignored.
- **Portable.** MySQL/MariaDB, PostgreSQL and SQLite, using full-text indexes where you declare them.

Requires PHP 8.3+ and Laravel 11 or 12. There are no migrations.

## Installation

```bash
composer require atannex/ecosystem
php artisan vendor:publish --tag=ecosystem-config   # optional
```

## Making a model searchable

```php
use Atannex\Ecosystem\Search\Searchable;
use Atannex\Ecosystem\Search\SearchOptions;

class Post extends Model
{
    use Searchable;

    public function searchOptions(): SearchOptions
    {
        return SearchOptions::create()
            ->field('title', 10)                              // weight 10, matched anywhere
            ->field('description', 3)
            ->field('author.user.name', 4, as: 'author')       // relation, two levels deep
            ->field('tags.name', 5, as: 'tag')                 // many-to-many
            ->field('region.parent.name', 1, as: 'division')   // self-relation
            ->field('metadata->city', 2, as: 'city')           // JSON path
            ->exact('code', 8)                                 // whole value only (codes, emails)
            ->prefix('slug', 2)                                // starts with
            ->filter('views')                                  // views:>100
            ->filter('published_at', as: 'date')               // date:2026-01-01..2026-03-31
            ->title('title')
            ->description('description')
            ->url(fn (Post $post) => route('posts.show', $post));
    }
}
```

Without `searchOptions()`, the model searches every text column of its table, skipping hidden attributes and passwords.

`search()` is an ordinary query scope, so the usual query building still works:

```php
Post::query()
    ->published()
    ->search($request->input('q'))
    ->with('author.user')
    ->paginate(12);
```

- Ranked results get a `search_score` attribute and are sorted by it. Orders added before `search()` take priority.
- Empty or too-short input leaves the query unchanged.
- Use `->withoutRanking()` to keep your own order.
- Use `->matchAny()` to return rows matching any term instead of all of them.

Add points of your own to the score with `boost()`: freshness, popularity, a bonus when the whole query is in the title.

```php
SearchOptions::create()
    ->field('title', 10)
    ->boost('CASE WHEN posts.published_at >= ? THEN 5 ELSE 0 END', [now()->subWeek()])
    ->boost(fn (ParsedQuery $query) => count($query->required()) < 2 ? null : [
        'CASE WHEN LOWER('.Search::column(new Post, 'title').') LIKE ? THEN 40 ELSE 0 END',
        ['%'.mb_strtolower(implode(' ', array_map(fn ($t) => $t->value, $query->required()))).'%'],
    ]);
```

A closure receives the parsed query and returns `[sql, bindings]`, or `null` to skip.

Override the options for one query: `Post::search($q, SearchOptions::create()->field('title'))`.
Search any query without the trait: `Search::apply(Order::query(), $q, SearchOptions::create()->exact('number'))`.

## Search syntax

| Input | Meaning |
|---|---|
| `lebialem chiefs` | both words, each in any field |
| `"traditional marriage"` | the exact phrase |
| `fontem -dance` | without "dance" |
| `bang*` | a word starting with "bang" ("The Bangwa people") |
| `author:collins` | only in the field named `author` (alias or path) |
| `-author:collins` | not by that author |
| `views:>100` `views:<=5` | comparisons on a `filter()` |
| `date:2026-01-01..2026-03-31` | inclusive range; a date-only end covers the whole day |
| `status:published` | equality on a `filter()` |

Unknown fields are treated as plain text, so `10:30` or a URL still search normally. Queries are capped at 200 characters and 8 terms. Plain words shorter than 2 characters are ignored, but quoted or field terms are kept. Words in the `stopwords` config list (`the`, `of`…) are ignored unless the query has nothing else.

`Search::parse($q)->toQuery()` gives the query back in this syntax. `ParsedQuery` also has `words()` (plain words, lowercased), `withoutFields()`, `hasText()`, `isRankable()` (something besides comparisons) and `rewrite(['royl' => 'royal'])`.

### Did you mean

```php
$vocabulary = Search::vocabulary($titlesAndNames);          // distinct lowercase words (3+ letters)
Search::suggest('royl palce tag:culture', $vocabulary);     // "royal palace tag:culture", or null
```

Unknown plain words are swapped for the closest known word: one typo for short words, two from six letters. Phrases, exclusions, field filters, numbers and words under four letters are left alone. Check that the suggestion finds something before offering it.

## Relevance

Each field adds **weight × quality** for each term it matches:

| Match | Own columns | Related fields |
|---|---|---|
| exact (whole value, any case) | × 3 | × 3 for `exact()` fields |
| starts with | × 2 | × 2 for `prefix()` fields |
| contains | × 1 | × 1 |

Own columns are graded, so an exact title beats a title that merely contains the word. Each related field costs one `EXISTS` subquery, so add the weights you need rather than every relation. Change the multipliers in `config/ecosystem.php`.

Reward the reader's words appearing together, in order, with `boostPhrase()`. The words are escaped, so `%` and `_` in a query are literal:

```php
SearchOptions::create()->field('title', 12)->boostPhrase('title', 40); // needs 2+ plain words
```

Boost closures receive the parsed query and a resolver from a field path to its SQL column: `->boost(fn (ParsedQuery $q, Closure $column) => ["CASE WHEN {$column('title')} …", []])`.

## Full-text indexes

For long text on big tables, declare a full-text index and search it:

```php
// migration
$table->fullText(['title', 'body']);

// model
SearchOptions::create()->fullText(['title', 'body'], 5)->field('author.name', 2);
```

- **MySQL/MariaDB** use boolean mode. All words are required, `word*` searches by prefix and phrases are supported, and operators typed by visitors are stripped.
- **PostgreSQL** uses `plainto_tsquery` (`phrase` mode for quoted input).
- **SQLite and others** fall back to LIKE.

## Translatable and computed columns

When a model has `isTranslatableAttribute()` (spatie/laravel-translatable), its translatable columns are searched in `app()->getLocale()`, falling back to `app.fallback_locale`, and compared in lowercase (JSON values can compare case-sensitively). Turn it off with `translatable => false`.

For any other column that needs SQL of its own, register a resolver. It receives the model (with its aliased table in self-relations) and the column, and returns SQL or `null`:

```php
Search::resolveColumnsUsing(fn (Model $model, string $column) => $column === 'phone'
    ? "REPLACE({$model->qualifyColumn('phone')}, ' ', '')"
    : null);

Search::column(new Post, 'title');   // the SQL searched for a column, for your own queries
```

## Global search

```php
use Atannex\Ecosystem\Facades\Search;

$hits = Search::across([
    Post::class => fn ($q) => $q->published(),   // constrain a model
    Document::class,
    Region::class,
])->query($request->q)->perType(5)->get();       // Collection<SearchHit>, best first

$groups = Search::across([...])->query($q)->grouped();   // ['Posts' => hits, 'Documents' => hits]
```

A `SearchHit` has `type`, `title`, `description`, `url`, `score` and `model`, and serializes to JSON for APIs. Labels, titles and URLs come from `->label()`, `->title()`, `->description()` and `->url()`. Scores are comparable across models only if their weights are on a similar scale.

## Highlighting

```blade
{{ Search::highlight($post->title, $q) }}          {{-- "Village <mark>dance</mark>" --}}
{{ Search::excerpt($post->body, $q, 160) }}        {{-- …around the first match… --}}
```

Both return HTML-escaped `HtmlString`s, so they're safe to echo even when the text contains markup typed by users, and a term never matches inside an entity (`amp` in `&amp;`). `excerpt()` strips tags from rich text first. Set the tag and an optional class in the config (`highlight_tag`, `highlight_class`).

## From the terminal

```bash
php artisan ecosystem:search "dance -wedding" --model="App\Models\Posts\Post" --model="App\Models\Docs\Document"
php artisan ecosystem:search "dance" --model="App\Models\Posts\Post" --sql   # print the SQL
```

## Notes

- **Case and accents** follow the column collation on MySQL (`utf8mb4_unicode_ci` ignores both). PostgreSQL uses `ILIKE`, which ignores case only. SQLite ignores case for ASCII.
- **`contains` matches can't use a normal index** (`LIKE '%…%'`). `prefix()` and `exact()` can, and full-text indexes scale best for long text.
- **Polymorphic (`morphTo`) relations** aren't supported in field paths.

## Testing

```bash
composer install && composer test
# against MySQL:
ECOSYSTEM_TEST_DB=mysql DB_HOST=127.0.0.1 DB_DATABASE=ecosystem_test DB_USERNAME=root DB_PASSWORD= composer test
```

## License

MIT
