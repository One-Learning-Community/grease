<?php

namespace Grease\Support;

/**
 * A wildcard event pattern with its regex pre-compiled once, so repeated match
 * checks skip the `preg_quote` + `str_replace` that `Str::is` redoes on every call.
 *
 * `matches()` reproduces `Str::is($pattern, $value)` exactly (the comparison the
 * stock dispatcher uses for wildcard listeners): the `*`/exact-string short
 * circuits, then the same `#^…\z#su` regex. The events-dispatcher parity suite
 * asserts that equivalence against the real framework — if a future Laravel
 * changes `Str::is`, that test fails loudly rather than silently diverging.
 */
final class WildcardPattern
{
    private string $regex;

    public function __construct(public readonly string $pattern)
    {
        $this->regex = '#^'.str_replace('\*', '.*', preg_quote($pattern, '#')).'\z#su';
    }

    public function matches(string $value): bool
    {
        return $this->pattern === '*'
            || $this->pattern === $value
            || preg_match($this->regex, $value) === 1;
    }
}
