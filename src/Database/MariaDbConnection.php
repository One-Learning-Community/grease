<?php

namespace Grease\Database;

use Grease\Database\Concerns\FlushesWrapMemoOnPrefixChange;
use Grease\Database\Query\Grammars\MariaDbGrammar;
use Illuminate\Database\MariaDbConnection as BaseConnection;

/** MariaDB connection that builds the greased (wrap-memoizing) query grammar. */
class MariaDbConnection extends BaseConnection
{
    use FlushesWrapMemoOnPrefixChange;

    protected function getDefaultQueryGrammar()
    {
        return new MariaDbGrammar($this);
    }
}
