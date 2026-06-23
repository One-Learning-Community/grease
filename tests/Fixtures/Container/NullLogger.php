<?php

namespace Grease\Tests\Fixtures\Container;

/** A second concrete logger, for testing late-bound contextual/rebind resolution. */
class NullLogger implements LoggerContract
{
    public string $kind = 'null';
}
