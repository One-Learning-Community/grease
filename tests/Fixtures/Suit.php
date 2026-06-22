<?php

namespace Grease\Tests\Fixtures;

/**
 * A pure/unit enum (no backing). This is the case a reimplementation of the cast
 * would get wrong: conversion is `constant("$class::$value")`, not `$class::from()`.
 * Proves the enum fast path delegates rather than assuming a backed enum.
 */
enum Suit
{
    case Hearts;
    case Spades;
}
