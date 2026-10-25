<?php

declare(strict_types=1);

namespace Atannex\Ecosystem\Search;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Applies a search to an Eloquent query: filters the rows and, when ranking,
 * adds a "search_score" column and sorts by it, all in a single SQL query.
 *
 * Matching: every term must match at least one field (or any term, with
 * matchAny()). Relation fields become EXISTS sub-queries at any depth, so
 * soft deletes and other scopes of related models are respected.
 *
 * Scoring: field weight × match quality. On the model's own columns an exact
 * match scores higher than a prefix match, which beats a match anywhere;
 * related fields score their weight × the quality of their match mode.
 *
 * Columns: a translatable column (spatie/laravel-translatable, JSON keyed by
 * locale) is searched in the current locale with a fallback to the default
 * one. resolveColumnsUsing() supplies the SQL for any other computed column.
 */
class SearchEngine
{
    /** @var (Closure(Model, string): ?string)|null */
    private ?Closure $columnResolver = null;

    /**
     * @param  array{exact?: float, prefix?: float, contains?: float}  $scores
     */
    public function __construct(
        private readonly QueryParser $parser,
        private readonly array $scores = [],
        private readonly bool $translatable = true,
    ) {}

    /**
     * SQL to search instead of a plain column, for columns that need it:
     * fn (Model $model, string $column): ?string. Return null to use the
     * column as it is (or the built-in translatable handling).
     *
     * @param  (Closure(Model, string): ?string)|null  $resolver
     */
    public function resolveColumnsUsing(?Closure $resolver): void
    {
        $this->columnResolver = $resolver;
    }

    /**
     * The SQL searched for a column of $model: the wrapped, qualified column,
     * or an expression for translatable and resolved columns.
     */
    public function column(Model $model, string $column): string
    {
        return $this->expression($model, $column)
            ?? $model->getConnection()->getQueryGrammar()->wrap($model->qualifyColumn($column));
    }

    public function parse(?string $input): ParsedQuery
    {
        return $this->parser->parse($input);
    }

    public function apply(Builder $query, string|ParsedQuery|null $input, SearchOptions $options): Builder
    {
        $parsed = $input instanceof ParsedQuery ? $input : $this->parser->parse($input);

        if ($parsed->isEmpty()) {
            return $query;
        }

        $model = $query->getModel();
        $fields = $options->resolveFields($model);
        $positive = [];

        foreach ($parsed->terms as $term) {
            $targets = $fields;

            if ($term->field !== null) {
                $field = $options->lookup($model, $term->field, $term->isComparison());

                if ($field === null) {
                    // Unknown field: "10:30" or "http://…" are just text.
                    $term = new Term($term->raw(), phrase: true, negated: $term->negated);
                } elseif ($term->isComparison()) {
                    $this->compare($query, $field, $term);

                    continue;
                } else {
                    $targets = [$field];
                }
            }

            if ($targets === []) {
                // Nothing to search in: no row can match (and nothing to exclude).
                if (! $term->negated) {
                    $query->whereRaw('1 = 0');
                }

                continue;
            }

            if ($term->negated) {
                $this->exclude($query, fn (Builder $q) => $this->matchAny($q, $targets, $term));
            } else {
                $positive[] = [$term, $targets];
            }
        }

        $this->constrain($query, $positive, $options->matchAll);

        if ($options->rank && $positive !== []) {
            $this->rank($query, $positive, $options->resolveBoosts($parsed, fn (string $path): string => $this->column($query->getModel(), $path)));
        }

        return $query;
    }

    /* -----------------------------------------------------------------
     |  Matching
     |-----------------------------------------------------------------*/

    /**
     * @param  array<int, array{0: Term, 1: array<int, Field>}>  $positive
     */
    private function constrain(Builder $query, array $positive, bool $matchAll): void
    {
        if ($positive === []) {
            return;
        }

        if ($matchAll) {
            foreach ($positive as [$term, $fields]) {
                $query->where(fn (Builder $q) => $this->matchAny($q, $fields, $term));
            }

            return;
        }

        $query->where(function (Builder $q) use ($positive): void {
            foreach ($positive as [$term, $fields]) {
                $q->orWhere(fn (Builder $inner) => $this->matchAny($inner, $fields, $term));
            }
        });
    }

    /**
     * @param  array<int, Field>  $fields
     */
    private function matchAny(Builder $query, array $fields, Term $term): void
    {
        foreach ($fields as $field) {
            $this->match($query, $field, $term, $this->modeFor($field, $term), 'or');
        }
    }

    private function match(Builder $query, Field $field, Term $term, MatchMode $mode, string $boolean): void
    {
        if (! $field->isRelated()) {
            $this->condition($query, $field, $term, $mode, $boolean);

            return;
        }

        $query->has(
            $field->relation,
            '>=',
            1,
            $boolean,
            fn (Builder $related) => $this->condition($related, $field, $term, $mode, 'and'),
        );
    }

    /**
     * Compare one column (of the query's model) with a value.
     */
    private function condition(Builder $query, Field $field, Term $term, MatchMode $mode, string $boolean): void
    {
        $grammar = $query->getQuery()->getGrammar();
        $value = $term->value;

        if ($mode === MatchMode::FullText) {
            $columns = array_map($query->qualifyColumn(...), $field->columns);

            if ($this->supportsFullText($query)) {
                $this->fullTextCondition($query, $columns, $term, $boolean);

                return;
            }

            $query->where(function (Builder $group) use ($columns, $value, $grammar): void {
                foreach ($columns as $column) {
                    $group->orWhereRaw($this->like($group, $grammar->wrap($column)), [$this->pattern($value, MatchMode::Contains)]);
                }
            }, boolean: $boolean);

            return;
        }

        // Expressions (JSON values) may compare case-sensitively, so they are
        // matched in lowercase; plain columns keep their collation (and index).
        $expression = $this->expression($query->getModel(), $field->column);
        $column = $expression === null ? $grammar->wrap($query->qualifyColumn($field->column)) : 'LOWER('.$expression.')';
        $value = $expression === null ? $value : mb_strtolower($value);

        if ($mode === MatchMode::Exact) {
            $query->whereRaw("LOWER({$column}) = LOWER(?)", [$value], $boolean);

            return;
        }

        // "bang*" from a visitor: any word starting with "bang", not only the
        // start of the value ("The Bangwa people" matches).
        if ($term->prefix && $mode === MatchMode::Contains) {
            $like = $this->like($query, $column);
            $query->whereRaw("({$like} OR {$like})", [$this->pattern($value, MatchMode::Prefix), '% '.$this->pattern($value, MatchMode::Prefix)], $boolean);

            return;
        }

        $query->whereRaw($this->like($query, $column), [$this->pattern($value, $mode)], $boolean);
    }

    /**
     * Full-text match using the index. On MySQL boolean mode is used (natural
     * language mode hides words found in over half of the rows, so small
     * tables find nothing); the visitor's text is stripped of the mode's
     * operators so input cannot change the query.
     *
     * @param  array<int, string>  $columns
     */
    private function fullTextCondition(Builder $query, array $columns, Term $term, string $boolean): void
    {
        if ($query->getConnection()->getDriverName() === 'pgsql') {
            $query->whereFullText($columns, $term->value, ['mode' => $term->phrase ? 'phrase' : 'plain'], $boolean);

            return;
        }

        $words = trim((string) preg_replace('/[+\-<>()~*"@]+/', ' ', $term->value));

        if ($words === '') {
            $query->whereRaw('1 = 0', [], $boolean);

            return;
        }

        $search = match (true) {
            $term->phrase => '"'.$words.'"',
            $term->prefix => implode(' ', array_map(fn (string $word) => '+'.$word.'*', preg_split('/\s+/', $words) ?: [])),
            default => implode(' ', array_map(fn (string $word) => '+'.$word, preg_split('/\s+/', $words) ?: [])),
        };

        $query->whereFullText($columns, $search, ['mode' => 'boolean'], $boolean);
    }

    private function compare(Builder $query, Field $field, Term $term): void
    {
        $apply = function (Builder $q) use ($field, $term): void {
            $column = $q->qualifyColumn($field->column);

            if ($term->operator === '..') {
                $q->whereBetween($column, [$term->value, $this->endOfRange((string) $term->until)]);
            } else {
                $q->where($column, $term->operator, $term->value);
            }
        };

        $constraint = $field->isRelated()
            ? fn (Builder $q) => $q->whereHas($field->relation, $apply)
            : $apply;

        $term->negated ? $this->exclude($query, $constraint) : $query->where($constraint);
    }

    /**
     * Drop the rows matching $constraint. Written as "key NOT IN (matching
     * rows)" rather than NOT (…), because NOT (NULL LIKE …) is NULL in SQL and
     * would also drop rows whose column is empty.
     */
    private function exclude(Builder $query, Closure $constraint): void
    {
        $model = $query->getModel();

        $query->whereNotIn(
            $model->getQualifiedKeyName(),
            $model->newQueryWithoutScopes()->select($model->getQualifiedKeyName())->where($constraint),
        );
    }

    /* -----------------------------------------------------------------
     |  Ranking
     |-----------------------------------------------------------------*/

    /**
     * @param  array<int, array{0: Term, 1: array<int, Field>}>  $positive
     * @param  array<int, array{0: string, 1: array<int, mixed>}>  $boosts
     */
    private function rank(Builder $query, array $positive, array $boosts = []): void
    {
        $parts = [];
        $bindings = [];

        foreach ($positive as [$term, $fields]) {
            foreach ($fields as $field) {
                [$sql, $values] = $this->scoreFor($query->getModel(), $field, $term);
                $parts[] = $sql;
                array_push($bindings, ...$values);
            }
        }

        foreach ($boosts as [$sql, $values]) {
            $parts[] = '('.$sql.')';
            array_push($bindings, ...$values);
        }

        if ($query->getQuery()->columns === null) {
            $query->select($query->qualifyColumn('*'));
        }

        $query->selectRaw('('.implode(' + ', $parts).') as search_score', $bindings)
            ->orderByDesc('search_score');
    }

    /**
     * CASE expression worth weight × the best match quality of one field.
     *
     * @return array{0: string, 1: array<int, mixed>}
     */
    private function scoreFor(Model $model, Field $field, Term $term): array
    {
        $mode = $this->modeFor($field, $term);

        // Graded on own columns (cheap); one EXISTS per related field.
        $grades = match (true) {
            $field->isRelated(), $mode === MatchMode::FullText, $mode === MatchMode::Exact => [$mode],
            $mode === MatchMode::Prefix => [MatchMode::Exact, MatchMode::Prefix],
            default => [MatchMode::Exact, MatchMode::Prefix, MatchMode::Contains],
        };

        $sql = 'CASE';
        $bindings = [];

        foreach ($grades as $grade) {
            $probe = $model->newQueryWithoutScopes();
            $this->match($probe, $field, $term, $grade, 'and');

            $base = $probe->getQuery();
            $sql .= ' WHEN '.preg_replace('/^where /i', '', $base->getGrammar()->compileWheres($base)).' THEN ?';
            array_push($bindings, ...[...$base->getBindings(), $field->weight * $this->score($grade)]);
        }

        return [$sql.' ELSE 0 END', $bindings];
    }

    /* -----------------------------------------------------------------
     |  Helpers
     |-----------------------------------------------------------------*/

    private function modeFor(Field $field, Term $term): MatchMode
    {
        // Prefix terms ("bang*") keep the field's mode; condition() narrows a
        // Contains match to word starts.
        return $field->mode;
    }

    /**
     * Translatable or resolved column SQL, null for a plain column.
     */
    private function expression(Model $model, string $column): ?string
    {
        if (str_contains($column, '->')) {
            return null;
        }

        if ($this->columnResolver !== null && ($sql = ($this->columnResolver)($model, $column)) !== null) {
            return $sql;
        }

        if (! $this->translatable || ! method_exists($model, 'isTranslatableAttribute') || ! $model->isTranslatableAttribute($column)) {
            return null;
        }

        $grammar = $model->getConnection()->getQueryGrammar();
        $qualified = $model->qualifyColumn($column);
        $locales = array_values(array_unique(array_filter([app()->getLocale(), (string) config('app.fallback_locale')])));

        return count($locales) === 1
            ? $grammar->wrap("{$qualified}->{$locales[0]}")
            : 'COALESCE('.implode(', ', array_map(fn (string $locale) => $grammar->wrap("{$qualified}->{$locale}"), $locales)).')';
    }

    private function score(MatchMode $mode): float
    {
        return (float) match ($mode) {
            MatchMode::Exact => $this->scores['exact'] ?? 3,
            MatchMode::Prefix => $this->scores['prefix'] ?? 2,
            MatchMode::Contains, MatchMode::FullText => $this->scores['contains'] ?? 1,
        };
    }

    private function like(Builder $query, string $wrappedColumn): string
    {
        // PostgreSQL's LIKE is case-sensitive; MySQL and SQLite compare case-insensitively.
        $operator = $query->getConnection()->getDriverName() === 'pgsql' ? 'ILIKE' : 'LIKE';

        return "{$wrappedColumn} {$operator} ? ESCAPE '!'";
    }

    /**
     * LIKE pattern with the user's own %, _ and ! taken literally.
     */
    private function pattern(string $value, MatchMode $mode): string
    {
        return ($mode === MatchMode::Prefix ? '' : '%').self::escapeLike($value).'%';
    }

    /**
     * A value for LIKE … ESCAPE '!', with its own %, _ and ! taken literally.
     */
    public static function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }

    private function supportsFullText(Builder $query): bool
    {
        return in_array($query->getConnection()->getDriverName(), ['mysql', 'mariadb', 'pgsql'], true);
    }

    /**
     * "2026-03-31" as the end of a range means the whole day.
     */
    private function endOfRange(string $value): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value.' 23:59:59' : $value;
    }
}
