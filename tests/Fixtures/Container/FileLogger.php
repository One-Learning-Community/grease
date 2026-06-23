<?php

namespace Grease\Tests\Fixtures\Container;

class FileLogger implements LoggerContract
{
    public string $kind = 'file';
}
