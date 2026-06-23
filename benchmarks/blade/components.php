<?php

/**
 * Class-based components for the page-shaped Blade macro (`page-app`).
 *
 * The existing simple/rich pages are 1,000× one *anonymous* avatar — they only
 * exercise the @props + merge path. A real page also runs class components
 * (container instantiation + dependency resolution + the Factory), named &
 * slotted slots, nested components, @include/@each and a view composer. These
 * three small classes give the macro that surface so the profiler sees where
 * time actually concentrates across a realistic render, not just the micro.
 */

namespace Grease\Benchmarks\Blade;

use Illuminate\View\Component;

class Layout extends Component
{
    public function __construct(public string $title = 'App') {}

    public function render()
    {
        return view('components.layout');
    }
}

class Card extends Component
{
    public function __construct(public string $title = '', public bool $elevated = false) {}

    public function render()
    {
        return view('components.card');
    }
}

class Stat extends Component
{
    public function __construct(public string $label, public int $value = 0) {}

    public function render()
    {
        return view('components.stat');
    }
}
