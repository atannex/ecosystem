<?php

declare(strict_types=1);

namespace Atannex\Ecosystem\Search;

use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * Marks search terms in text for display. Output is HTML-escaped first, so it
 * is safe to echo even when the text holds markup typed by users.
 */
final class Highlighter
{
    private readonly string $open;

    private readonly string $close;

    public function __construct(string $tag = 'mark', ?string $class = null)
    {
        $tag = preg_match('/^[a-z][a-z0-9-]*$/i', $tag) ? $tag : 'mark';

        $this->open = '<'.$tag.($class === null || $class === '' ? '' : ' class="'.e($class).'"').'>';
        $this->close = '</'.$tag.'>';
    }

    /**
     * @param  array<int, string>  $terms
     */
    public function highlight(?string $text, array $terms): HtmlString
    {
        $text = (string) $text;
        $pattern = $this->pattern($terms);

        if ($pattern === null) {
            return new HtmlString(e($text));
        }

        // Split the raw text and escape each piece, so a term can never match
        // inside an entity ("amp" in "&amp;") or the markup added here.
        $parts = preg_split($pattern, $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text];
        $html = '';

        foreach ($parts as $i => $part) {
            $html .= $i % 2 === 1 ? $this->open.e($part).$this->close : e($part);
        }

        return new HtmlString($html);
    }

    /**
     * About $length characters of plain text around the first match, with the
     * terms highlighted. Markup in $text is stripped first (article bodies).
     *
     * @param  array<int, string>  $terms
     */
    public function excerpt(?string $text, array $terms, int $length = 160): HtmlString
    {
        $plain = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $text), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        $position = $this->firstMatch($plain, $terms);

        if ($position === null || mb_strlen($plain) <= $length) {
            return $this->highlight(Str::limit($plain, $length), $terms);
        }

        $start = max(0, $position - intdiv($length, 3));
        $snippet = mb_substr($plain, $start, $length);

        // Start and end on word boundaries.
        if ($start > 0) {
            $snippet = '…'.ltrim((string) preg_replace('/^\S*\s/u', '', $snippet, 1));
        }

        if ($start + $length < mb_strlen($plain)) {
            $snippet = rtrim((string) preg_replace('/\s\S*$/u', '', $snippet)).'…';
        }

        return $this->highlight($snippet, $terms);
    }

    /**
     * @param  array<int, string>  $terms
     */
    private function firstMatch(string $text, array $terms): ?int
    {
        $positions = array_filter(array_map(fn (string $term) => mb_stripos($text, $term), $terms), 'is_int');

        return $positions === [] ? null : min($positions);
    }

    /**
     * @param  array<int, string>  $terms
     */
    private function pattern(array $terms): ?string
    {
        $terms = array_filter(array_map('trim', $terms), fn (string $term) => $term !== '');

        if ($terms === []) {
            return null;
        }

        // Longest first, so "dance group" wins over "dance".
        usort($terms, fn (string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));

        return '/('.implode('|', array_map(fn (string $term) => preg_quote($term, '/'), $terms)).')/iu';
    }
}
