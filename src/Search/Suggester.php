<?php

declare(strict_types=1);

namespace Atannex\Ecosystem\Search;

/**
 * "Did you mean": plain words the vocabulary does not know are swapped for
 * the closest known word (one typo in short words, two from six letters).
 * Digits and words under four letters are left alone.
 */
final class Suggester
{
    /**
     * The corrected query in search syntax, or null when nothing changed.
     *
     * @param  iterable<int, string>  $vocabulary  known lowercase words
     */
    public function suggest(ParsedQuery $query, iterable $vocabulary): ?string
    {
        $words = $query->words();
        $vocabulary = is_array($vocabulary) ? $vocabulary : iterator_to_array($vocabulary, false);

        if ($words === [] || $vocabulary === []) {
            return null;
        }

        $known = array_flip($vocabulary);
        $corrections = [];

        foreach ($words as $word) {
            if (mb_strlen($word) < 4 || isset($known[$word]) || ctype_digit($word)) {
                continue;
            }

            if (($best = $this->closest($word, $vocabulary)) !== null) {
                $corrections[$word] = $best;
            }
        }

        return $corrections === [] ? null : $query->rewrite($corrections);
    }

    /**
     * Distinct lowercase words (at least $minLength letters) in some texts:
     * titles, names, tags… to suggest from.
     *
     * @param  iterable<int, string|null>  $texts
     * @return list<string>
     */
    public function vocabulary(iterable $texts, int $minLength = 3): array
    {
        $words = [];

        foreach ($texts as $text) {
            foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower((string) $text), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
                if (mb_strlen($word) >= $minLength) {
                    $words[$word] = true;
                }
            }
        }

        // Numeric keys ("2026") come back as ints; the distance checks need strings.
        return array_map('strval', array_keys($words));
    }

    /**
     * @param  list<string>  $vocabulary
     */
    private function closest(string $word, array $vocabulary): ?string
    {
        $best = null;
        $bestDistance = PHP_INT_MAX;
        $allowed = mb_strlen($word) >= 6 ? 2 : 1;

        foreach ($vocabulary as $candidate) {
            $candidate = (string) $candidate; // cached vocabularies may hold ints ("2026")

            if (abs(mb_strlen($candidate) - mb_strlen($word)) > $allowed) {
                continue;
            }

            $distance = levenshtein($word, $candidate);

            if ($distance <= $allowed && $distance < $bestDistance) {
                [$best, $bestDistance] = [$candidate, $distance];
            }
        }

        return $best;
    }
}
