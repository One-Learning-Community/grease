<?php

namespace Grease\Support;

/**
 * An array of wildcard patterns compiled once into the fastest faithful equivalent of
 * `Str::is($patterns, $value)`:
 *
 *   - literal patterns (no `*`)    → an O(1) `isset()` hash (exact match)
 *   - a bare `*`                   → match-everything short circuit
 *   - wildcard patterns (with `*`) → ONE merged alternation regex, a single `preg_match`
 *
 * `Str::is` fed an array re-runs `preg_quote` + `str_replace` + `preg_match` per pattern on
 * every call, and for a non-matching value walks ALL patterns before returning false. Compiling
 * the set once turns the common literal case into a hash lookup and collapses N wildcard
 * `preg_match` calls into one. Byte-identical to `Str::is($patterns, $value)` (case-sensitive —
 * the default); tests/CompiledPatternSetParityTest.php asserts that against the real
 * framework across pattern kinds.
 *
 * Like `Str::is`, ONLY `*` is a wildcard — every other character (`?`, `[`, `.`, …) is a
 * literal, matched via `preg_quote`. This is not `fnmatch`.
 */
final class CompiledPatternSet
{
    /** @var array<string, true> exact-match (literal) patterns */
    private array $literals = [];

    private bool $matchesEverything = false;

    private ?string $regex = null;

    /**
     * @param  iterable<string>  $patterns
     */
    public function __construct(iterable $patterns)
    {
        $wild = [];

        foreach ($patterns as $pattern) {
            $pattern = (string) $pattern;

            if ($pattern === '*') {
                $this->matchesEverything = true;

                continue;
            }

            if (! str_contains($pattern, '*')) {
                $this->literals[$pattern] = true;

                continue;
            }

            // The exact translation Str::is does: quote everything, then `\*` → `.*`.
            $wild[] = str_replace('\*', '.*', preg_quote($pattern, '#'));
        }

        if ($wild !== []) {
            $this->regex = '#^(?:'.implode('|', $wild).')\z#su';
        }
    }

    /**
     * Whether $value matches any pattern in the set — identical to
     * `Str::is($patterns, $value)`.
     */
    public function matches(string $value): bool
    {
        return $this->matchesEverything
            || isset($this->literals[$value])
            || ($this->regex !== null && preg_match($this->regex, $value) === 1);
    }
}
