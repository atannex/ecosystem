<?php

declare(strict_types=1);

namespace Atannex\Ecosystem\Search;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * What a model searches and how it shows up in results.
 *
 *     SearchOptions::create()
 *         ->field('title', 10)
 *         ->field('description', 3)
 *         ->field('author.user.name', 4, as: 'author')
 *         ->exact('code', 8)
 *         ->field('tags.name', 5, as: 'tag')
 *         ->filter('views')                          // views:>100
 *         ->filter('published_at', as: 'date')       // date:2026-01-01..2026-06-30
 *         ->title('title')
 *         ->url(fn (Post $post) => route('posts.show', $post));
 */
final class SearchOptions
{
    /** @var array<int, Field> */
    private array $fields = [];

    /** @var array<string, string> filter name => column path */
    private array $filters = [];

    /** @var array<int, array{0: string, 1: array<int, mixed>}|Closure> */
    private array $boosts = [];

    private bool $autoColumns = false;

    public bool $matchAll = true;

    public bool $rank = true;

    public ?string $label = null;

    private string|Closure|null $title = null;

    private string|Closure|null $description = null;

    private ?Closure $url = null;

    public static function create(): self
    {
        return new self;
    }

    /**
     * A field matched anywhere in its value (MatchMode::Contains by default).
     * $path may go through relations at any depth and into JSON:
     * "title", "author.user.name", "metadata->tags", "region.parent.name".
     */
    public function field(string $path, float $weight = 1, MatchMode $mode = MatchMode::Contains, ?string $as = null): self
    {
        if ($mode === MatchMode::FullText) {
            return $this->fullText([$path], $weight, $as);
        }

        $this->fields[] = new Field($path, $weight, $mode, $as);

        return $this;
    }

    /**
     * Several fields with weights: ['title' => 10, 'author.name' => 3].
     *
     * @param  array<string, float|int>|array<int, string>  $fields
     */
    public function fields(array $fields): self
    {
        foreach ($fields as $path => $weight) {
            is_int($path) ? $this->field($weight) : $this->field($path, (float) $weight);
        }

        return $this;
    }

    public function prefix(string $path, float $weight = 1, ?string $as = null): self
    {
        return $this->field($path, $weight, MatchMode::Prefix, $as);
    }

    public function exact(string $path, float $weight = 1, ?string $as = null): self
    {
        return $this->field($path, $weight, MatchMode::Exact, $as);
    }

    /**
     * Columns of one full-text index on the model's own table. Uses the index
     * on MySQL/MariaDB and PostgreSQL; other databases fall back to Contains.
     *
     * @param  array<int, string>  $columns
     */
    public function fullText(array $columns, float $weight = 1, ?string $as = null): self
    {
        foreach ($columns as $column) {
            if (str_contains($column, '.')) {
                throw new InvalidArgumentException("Full-text columns must belong to the model itself, [{$column}] does not.");
            }
        }

        $this->fields[] = new Field(implode(',', $columns), $weight, MatchMode::FullText, $as, $columns);

        return $this;
    }

    /**
     * A column usable in comparisons and ranges: "views:>100", "date:2026-01..2026-03".
     * Like fields, it may go through relations ("author.user.created_at").
     */
    public function filter(string $path, ?string $as = null): self
    {
        $this->filters[$as ?? $path] = $path;

        return $this;
    }

    /**
     * Extra points added to search_score when ranking: freshness, popularity…
     *
     *     ->boost('CASE WHEN posts.published_at >= ? THEN 5 ELSE 0 END', [now()->subWeek()])
     *     ->boost(fn (ParsedQuery $query) => $query->required() === [] ? null : ['…', [...]])
     *
     * A closure receives the parsed query and returns [sql, bindings] or null.
     *
     * @param  string|Closure(ParsedQuery): (array{0: string, 1: array<int, mixed>}|null)  $sql
     * @param  array<int, mixed>  $bindings
     */
    public function boost(string|Closure $sql, array $bindings = []): self
    {
        $this->boosts[] = $sql instanceof Closure ? $sql : [$sql, $bindings];

        return $this;
    }

    /**
     * Extra points when the reader's plain words appear together, in order,
     * in a field ("lebialem cultural festival" in a title). Their %, _ and !
     * are taken literally.
     */
    public function boostPhrase(string $path, float $points = 40, int $minWords = 2): self
    {
        return $this->boost(function (ParsedQuery $query, ?Closure $column = null) use ($path, $points, $minWords): ?array {
            $words = $query->words();

            if (count($words) < $minWords) {
                return null;
            }

            $sql = $column !== null ? $column($path) : $path;

            return [
                "CASE WHEN LOWER({$sql}) LIKE ? ESCAPE '!' THEN {$points} ELSE 0 END",
                ['%'.SearchEngine::escapeLike(implode(' ', $words)).'%'],
            ];
        });
    }

    /**
     * Also search every text column of the model's table (except hidden ones
     * such as passwords). Handy for admin tools and models without options.
     */
    public function autoColumns(bool $enabled = true): self
    {
        $this->autoColumns = $enabled;

        return $this;
    }

    /**
     * Records match when ANY term matches, instead of all of them.
     */
    public function matchAny(): self
    {
        $this->matchAll = false;

        return $this;
    }

    /**
     * Keep the query's own order instead of sorting by relevance.
     */
    public function withoutRanking(): self
    {
        $this->rank = false;

        return $this;
    }

    /* -----------------------------------------------------------------
     |  Presentation (global search results)
     |-----------------------------------------------------------------*/

    public function label(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    /**
     * @param  string|Closure(Model): ?string  $title  attribute or callback
     */
    public function title(string|Closure $title): self
    {
        $this->title = $title;

        return $this;
    }

    /**
     * @param  string|Closure(Model): ?string  $description
     */
    public function description(string|Closure $description): self
    {
        $this->description = $description;

        return $this;
    }

    /**
     * @param  Closure(Model): ?string  $url
     */
    public function url(Closure $url): self
    {
        $this->url = $url;

        return $this;
    }

    /* -----------------------------------------------------------------
     |  Resolution
     |-----------------------------------------------------------------*/

    /**
     * @return array<int, Field>
     */
    public function resolveFields(Model $model): array
    {
        if (! $this->autoColumns) {
            return $this->fields;
        }

        $configured = array_map(fn (Field $field) => $field->path, $this->fields);
        $auto = array_diff(self::textColumns($model), $configured);

        return [...$this->fields, ...array_map(fn (string $column) => new Field($column), array_values($auto))];
    }

    /**
     * @return array<int, array{0: string, 1: array<int, mixed>}>
     */
    public function resolveBoosts(ParsedQuery $query, ?Closure $column = null): array
    {
        $boosts = [];

        foreach ($this->boosts as $boost) {
            // Closures get the query and a resolver from field path to SQL column.
            $resolved = $boost instanceof Closure ? $boost($query, $column) : $boost;

            if ($resolved !== null) {
                $boosts[] = [(string) $resolved[0], array_values((array) ($resolved[1] ?? []))];
            }
        }

        return $boosts;
    }

    /**
     * The field or filter a "name:value" term refers to.
     */
    public function lookup(Model $model, string $name, bool $comparison): ?Field
    {
        if ($comparison) {
            return isset($this->filters[$name]) ? new Field($this->filters[$name], alias: $name) : null;
        }

        foreach ($this->resolveFields($model) as $field) {
            if ($field->name() === $name || $field->path === $name) {
                return $field;
            }
        }

        // A filter column also accepts plain values: "status:published".
        return isset($this->filters[$name]) ? new Field($this->filters[$name], mode: MatchMode::Exact, alias: $name) : null;
    }

    public function resolveLabel(Model $model): string
    {
        return $this->label ?? Str::headline(class_basename($model));
    }

    public function resolveTitle(Model $model): string
    {
        $title = $this->resolve($this->title, $model)
            ?? $model->getAttribute('title')
            ?? $model->getAttribute('name');

        return (string) ($title ?? $this->resolveLabel($model).' #'.$model->getKey());
    }

    public function resolveDescription(Model $model): ?string
    {
        return $this->resolve($this->description, $model);
    }

    public function resolveUrl(Model $model): ?string
    {
        return $this->url === null ? null : ($this->url)($model);
    }

    private function resolve(string|Closure|null $value, Model $model): ?string
    {
        $resolved = $value instanceof Closure ? $value($model) : ($value === null ? null : data_get($model, $value));

        return $resolved === null ? null : (string) $resolved;
    }

    /**
     * @var array<string, array<int, string>>
     */
    private static array $columnCache = [];

    /**
     * @return array<int, string>
     */
    private static function textColumns(Model $model): array
    {
        $key = $model->getConnectionName().'|'.$model->getTable();

        return self::$columnCache[$key] ??= array_values(array_diff(
            array_map(
                fn (array $column) => $column['name'],
                array_filter(
                    Schema::connection($model->getConnectionName())->getColumns($model->getTable()),
                    fn (array $column) => (bool) preg_match('/char|text|clob|string/i', (string) ($column['type_name'] ?? $column['type'] ?? '')),
                ),
            ),
            [...$model->getHidden(), 'password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'],
        ));
    }
}
