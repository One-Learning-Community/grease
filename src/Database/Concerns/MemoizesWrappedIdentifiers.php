<?php

namespace Grease\Database\Concerns;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Grammar;

/**
 * Memoize {@see Grammar::wrap()} — identifier quoting — by the raw string.
 *
 * `wrap('posts.id')` → `"posts"."id"` is a pure string transform run ~30+× per query, on every
 * query (every selected/where/join/order column + every table). The result is a pure function of
 * (the raw string, the connection's table prefix); the distinct identifier set an app uses is small
 * and bounded, so a per-grammar memo keyed by the raw string turns the re-walk into a hash hit.
 *
 * Non-string inputs (an `Expression`) bypass — they have no stable key and `getValue()` is already
 * cheap. A wrapped string is never null, so `??=` is safe (no null-memo trap).
 *
 * The ONE invalidation trigger is the **table prefix**: a dotted column's first segment is wrapped
 * as a table and receives the prefix, so the cached value embeds it. The greased connection flushes
 * this memo on `setTablePrefix()` (see {@see FlushesWrapMemoOnPrefixChange}); nothing else feeds
 * `wrap()`, so nothing else can invalidate it. Output stays byte-identical to vanilla.
 */
trait MemoizesWrappedIdentifiers
{
    /** @var array<string, string> */
    protected array $greaseWrapMemo = [];

    /**
     * Wrap a value in keyword identifiers — memoized for string inputs.
     *
     * @param  Expression|string  $value
     * @return string
     */
    public function wrap($value)
    {
        if (! is_string($value)) {
            return parent::wrap($value);
        }

        return $this->greaseWrapMemo[$value] ??= parent::wrap($value);
    }

    /**
     * Flush the wrap memo — called when the table prefix changes (the only input that can alter
     * a wrapped identifier).
     */
    public function flushGreaseWrapMemo(): void
    {
        $this->greaseWrapMemo = [];
    }
}
