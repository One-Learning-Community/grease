<?php

namespace Grease\Database;

use Grease\Database\Concerns\FlushesWrapMemoOnPrefixChange;
use Grease\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Database\MySqlConnection as BaseConnection;

/** MySQL connection that builds the greased (wrap-memoizing) query grammar. */
class MySqlConnection extends BaseConnection
{
    use FlushesWrapMemoOnPrefixChange;

    protected function getDefaultQueryGrammar()
    {
        return new MySqlGrammar($this);
    }
}
