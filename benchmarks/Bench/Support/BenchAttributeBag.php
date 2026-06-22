<?php

namespace Grease\Bench\Support;

/**
 * A thin stand-in for {@see \Illuminate\View\ComponentAttributeBag} (not a package
 * dependency). The real bag's constructor just stores the array and `all()` returns
 * it — faithful enough to size the per-render allocation the compiled `@props` block
 * does. Used by {@see \Grease\Bench\PropResolutionBench}.
 */
final class BenchAttributeBag
{
    public function __construct(private array $attributes = []) {}

    public function all(): array
    {
        return $this->attributes;
    }
}
