<?php

namespace Grease\Database\Query\Grammars;

use Grease\Database\Concerns\MemoizesWrappedIdentifiers;
use Illuminate\Database\Query\Grammars\PostgresGrammar as BaseGrammar;

/** PostgreSQL query grammar with a memoized identifier-wrap path. See {@see MemoizesWrappedIdentifiers}. */
class PostgresGrammar extends BaseGrammar
{
    use MemoizesWrappedIdentifiers;
}
