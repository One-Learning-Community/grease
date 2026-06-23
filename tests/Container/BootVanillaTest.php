<?php

namespace Grease\Tests\Container;

/**
 * Oracle arm: the same app + route on the stock Laravel container. Establishes the
 * byte-identical served response that {@see BootGreasedTest} must match.
 */
class BootVanillaTest extends BootParityTestCase
{
    // Uses Testbench's default (vanilla Illuminate\Foundation\Application).
}
