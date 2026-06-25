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
 * this memo on `setTablePrefix()` (see {@see FlushesWrapMemoOnPrefixChange}); the prefix is set
 * before the grammar is built and read live at wrap time, so `setTablePrefix` is the sole staleness
 * trigger — and `withoutTablePrefix()` / the deprecated `Grammar::setTablePrefix()` both round-trip
 * through it. Nothing else feeds `wrap()`/`wrapTable()`, so nothing else can invalidate them. Output
 * stays byte-identical to vanilla.
 *
 * Out of scope (exotic runtime surgery, the "95% scope" line): moving an already-warm grammar onto a
 * different connection via `Connection::setQueryGrammar()` would not re-flush — but the grammar still
 * holds a ref to its original connection, so vanilla behaves oddly there too. Not a supported path.
 */
trait MemoizesWrappedIdentifiers
{
    /** @var array<string, string> */
    protected array $greaseWrapMemo = [];

    /** @var array<string, string> */
    protected array $greaseWrapTableMemo = [];

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
     * Wrap a table in keyword identifiers — memoized for string tables under the default prefix.
     *
     * `wrapTable()` is a distinct pure transform from `wrap()` (its own alias/schema/prefix walk),
     * run for `from`, every join, and every insert/update/delete, on every query. Like `wrap()` its
     * output is a pure function of (the table string, the connection's table prefix) — the SAME key
     * domain and the SAME invalidation trigger — so it shares the prefix flush below.
     *
     * Only the default-prefix string case is memoized: an `Expression` has no stable key, and an
     * explicit non-null `$prefix` argument is outside the connection-prefix key domain (rare, and
     * would need a compound key) — both defer to vanilla.
     *
     * @param  Expression|string  $table
     * @param  string|null  $prefix
     * @return string
     */
    public function wrapTable($table, $prefix = null)
    {
        if ($prefix !== null || ! is_string($table)) {
            return parent::wrapTable($table, $prefix);
        }

        return $this->greaseWrapTableMemo[$table] ??= parent::wrapTable($table);
    }

    /**
     * Flush the wrap memos — called when the table prefix changes (the only input that can alter
     * a wrapped identifier or table).
     */
    public function flushGreaseWrapMemo(): void
    {
        $this->greaseWrapMemo = [];
        $this->greaseWrapTableMemo = [];
    }
}
