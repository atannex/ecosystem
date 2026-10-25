<?php

declare(strict_types=1);

namespace Atannex\Ecosystem\Search;

/**
 * A searchable field: a column of the model, of a relation at any depth
 * ("author.user.name"), or a JSON path ("metadata->tags", "author.profile->city").
 */
final class Field
{
    /** Relation path ("author.user"), null for the model's own column. */
    public readonly ?string $relation;

    /** Column on the last model of the path, JSON path included. */
    public readonly string $column;

    /**
     * @param  array<int, string>  $columns  Columns of a full-text index (FullText mode only).
     */
    public function __construct(
        public readonly string $path,
        public readonly float $weight = 1.0,
        public readonly MatchMode $mode = MatchMode::Contains,
        public readonly ?string $alias = null,
        public readonly array $columns = [],
    ) {
        // Dots before "->" are relations; anything after it is a JSON path.
        $json = strpos($path, '->');
        $head = $json === false ? $path : substr($path, 0, $json);
        $tail = $json === false ? '' : substr($path, $json);
        $dot = strrpos($head, '.');

        $this->relation = $dot === false ? null : substr($head, 0, $dot);
        $this->column = ($dot === false ? $head : substr($head, $dot + 1)).$tail;
    }

    /**
     * Name used in "field:value" filters.
     */
    public function name(): string
    {
        return $this->alias ?? $this->path;
    }

    public function isRelated(): bool
    {
        return $this->relation !== null;
    }
}
