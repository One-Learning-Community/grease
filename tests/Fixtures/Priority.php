<?php

namespace Grease\Tests\Fixtures;

/** A backed *int* enum — exercises the `$class::from($intValue)` conversion path. */
enum Priority: int
{
    case Low = 1;
    case High = 2;
}
