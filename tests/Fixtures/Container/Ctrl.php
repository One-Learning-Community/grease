<?php

namespace Grease\Tests\Fixtures\Container;

/**
 * The representative "controller": a few typed deps, one nested, plus a primitive with
 * a default. The per-request reality and the build-path benchmark's subject.
 */
class Ctrl
{
    public function __construct(
        public Dep1 $a,
        public LoggerContract $logger,
        public Dep3 $c,
        public int $n = 5,
    ) {}
}
