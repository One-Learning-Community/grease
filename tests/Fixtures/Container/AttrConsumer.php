<?php

namespace Grease\Tests\Fixtures\Container;

/** Consumes a contextual-attribute-resolved primitive. */
class AttrConsumer
{
    public function __construct(#[FromConfigValue('app.name')] public string $name) {}
}
