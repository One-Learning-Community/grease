<?php

namespace Grease\Database\Concerns;

/**
 * Flush the query grammar's identifier-wrap memo when the connection's table prefix changes.
 *
 * The wrap memo ({@see MemoizesWrappedIdentifiers}) embeds the table prefix in any dotted
 * identifier's first (table) segment, so a prefix change must invalidate it. Every prefix change
 * funnels through `setTablePrefix()` — including `withoutTablePrefix()`, which round-trips through
 * it — so flushing here is the single, complete invalidation point. The `method_exists` guard keeps
 * it safe if the grammar was swapped for a non-greased one.
 *
 * Each greased connection also overrides `getDefaultQueryGrammar()` to return its driver's greased
 * grammar (the wrap chain — `wrapValue`/`wrapJsonSelector` — stays driver-specific via `parent::`).
 */
trait FlushesWrapMemoOnPrefixChange
{
    /** {@inheritDoc} */
    public function setTablePrefix($prefix)
    {
        $result = parent::setTablePrefix($prefix);

        $grammar = $this->getQueryGrammar();

        if (method_exists($grammar, 'flushGreaseWrapMemo')) {
            $grammar->flushGreaseWrapMemo();
        }

        return $result;
    }
}
