<?php

declare(strict_types=1);

namespace Atannex\Ecosystem\Search;

/**
 * One part of a parsed query: a word, a "quoted phrase", a -negated term,
 * a prefix* term, or a field filter (field:value, field:>10, field:a..b).
 */
final class Term
{
    public function __construct(
        public readonly string $value,
        public readonly bool $phrase = false,
        public readonly bool $negated = false,
        public readonly bool $prefix = false,
        public readonly ?string $field = null,
        public readonly ?string $operator = null,
        public readonly ?string $until = null,
    ) {}

    /**
     * A comparison (>, >=, <, <=) or range (a..b) rather than a text match.
     */
    public function isComparison(): bool
    {
        return $this->operator !== null;
    }

    /**
     * The term as written, for falling back to text when a field is unknown.
     */
    public function raw(): string
    {
        $value = match ($this->operator) {
            null => $this->value,
            '..' => $this->value.'..'.$this->until,
            default => $this->operator.$this->value,
        };

        return $this->field === null ? $value : $this->field.':'.$value;
    }

    /**
     * The term in search syntax, so a query can be rebuilt ("did you mean").
     */
    public function toQuery(): string
    {
        $value = match (true) {
            $this->operator === '..' => $this->value.'..'.$this->until,
            $this->operator !== null => $this->operator.$this->value,
            $this->phrase => '"'.$this->value.'"',
            default => $this->value.($this->prefix ? '*' : ''),
        };

        return ($this->negated ? '-' : '').($this->field === null ? '' : $this->field.':').$value;
    }
}
