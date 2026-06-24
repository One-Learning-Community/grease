<?php

namespace Grease\Database\Query\Grammars;

use Grease\Database\Concerns\MemoizesWrappedIdentifiers;
use Illuminate\Database\Query\Grammars\MariaDbGrammar as BaseGrammar;

/**
 * MariaDB query grammar with a memoized identifier-wrap path. MariaDB's grammar extends MySQL's and
 * doesn't override `wrap()`, so the memo trait covers it identically. See {@see MemoizesWrappedIdentifiers}.
 */
class MariaDbGrammar extends BaseGrammar
{
    use MemoizesWrappedIdentifiers;
}
