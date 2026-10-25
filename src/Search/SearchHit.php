<?php

declare(strict_types=1);

namespace Atannex\Ecosystem\Search;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use JsonSerializable;

/**
 * One result of a global search, ready for a results list or an API.
 *
 * @implements Arrayable<string, mixed>
 */
final class SearchHit implements Arrayable, JsonSerializable
{
    public function __construct(
        public readonly Model $model,
        public readonly string $type,
        public readonly string $title,
        public readonly ?string $description,
        public readonly ?string $url,
        public readonly float $score,
    ) {}

    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'id' => $this->model->getKey(),
            'title' => $this->title,
            'description' => $this->description,
            'url' => $this->url,
            'score' => $this->score,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
