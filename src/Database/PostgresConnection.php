<?php

namespace Grease\Database;

use Grease\Database\Concerns\FlushesWrapMemoOnPrefixChange;
use Grease\Database\Query\Grammars\PostgresGrammar;
use Illuminate\Database\PostgresConnection as BaseConnection;

/** PostgreSQL connection that builds the greased (wrap-memoizing) query grammar. */
class PostgresConnection extends BaseConnection
{
    use FlushesWrapMemoOnPrefixChange;

    protected function getDefaultQueryGrammar()
    {
        return new PostgresGrammar($this);
    }
}
