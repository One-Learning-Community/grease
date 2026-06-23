<?php

namespace Grease\Container;

use Illuminate\Container\Container as BaseContainer;

/**
 * Drop-in greased container for non-Foundation users (packages, custom kernels).
 *
 * Behaviour-identical to {@see \Illuminate\Container\Container}; resolves transient
 * (non-singleton) bindings through a frozen per-concrete constructor blueprint. See
 * {@see ResolvesWithGreaseBlueprint}.
 */
class Container extends BaseContainer
{
    use ResolvesWithGreaseBlueprint;
}
