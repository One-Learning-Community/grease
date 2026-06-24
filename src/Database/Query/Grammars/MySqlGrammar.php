<?php

namespace Grease\Database\Query\Grammars;

use Grease\Database\Concerns\MemoizesWrappedIdentifiers;
use Illuminate\Database\Query\Grammars\MySqlGrammar as BaseGrammar;

/** MySQL query grammar with a memoized identifier-wrap path. See {@see MemoizesWrappedIdentifiers}. */
class MySqlGrammar extends BaseGrammar
{
    use MemoizesWrappedIdentifiers;
}
