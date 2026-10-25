<?php

declare(strict_types=1);

namespace Atannex\Ecosystem\Search;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * One query across several models, merged by relevance.
 *
 *     Search::across([
 *         Post::class => fn (Builder $q) => $q->published(),
 *         Document::class,
 *         Region::class,
 *     ])->query($request->q)->perType(5)->get();
 */
final class GlobalSearch
{
    /** @var array<class-string<Model>, Closure|null> */
    private array $models = [];

    private string|ParsedQuery|null $query = null;

    public function __construct(
        private readonly SearchEngine $engine,
        private int $perType = 5,
    ) {}

    /**
     * @param  array<int|class-string<Model>, class-string<Model>|Closure(Builder): mixed>  $models
     */
    public function in(array $models): self
    {
        foreach ($models as $key => $value) {
            [$class, $constraint] = is_string($key) ? [$key, $value] : [$value, null];

            if (! is_subclass_of($class, Model::class) || ! method_exists($class, 'scopeSearch')) {
                throw new InvalidArgumentException("{$class} is not an Eloquent model using Searchable.");
            }

            $this->models[$class] = $constraint;
        }

        return $this;
    }

    public function query(string|ParsedQuery|null $query): self
    {
        $this->query = $query;

        return $this;
    }

    public function perType(int $limit): self
    {
        $this->perType = max(1, $limit);

        return $this;
    }

    /**
     * Best hits of every model, highest score first.
     *
     * @return Collection<int, SearchHit>
     */
    public function get(): Collection
    {
        return $this->grouped()->flatten(1)->sortByDesc(fn (SearchHit $hit) => $hit->score)->values();
    }

    /**
     * Hits per type label, types in the order given.
     *
     * @return Collection<string, Collection<int, SearchHit>>
     */
    public function grouped(): Collection
    {
        $parsed = $this->query instanceof ParsedQuery ? $this->query : $this->engine->parse($this->query);

        if ($parsed->isEmpty()) {
            return collect();
        }

        return collect($this->models)
            ->mapWithKeys(function (?Closure $constraint, string $class) use ($parsed): array {
                $query = $class::query()->search($parsed);

                if ($constraint !== null) {
                    $constraint($query);
                }

                $hits = $query->limit($this->perType)->get()->map(fn (Model $model) => $model->toSearchHit());

                return [(new $class)->searchOptions()->resolveLabel(new $class) => $hits];
            })
            ->filter(fn (Collection $hits) => $hits->isNotEmpty());
    }
}
