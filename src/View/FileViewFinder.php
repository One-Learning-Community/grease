<?php

namespace Grease\View;

use Illuminate\View\FileViewFinder as BaseFinder;
use ReflectionClass;

/**
 * A view finder pre-seeded with an eager name→path index (see {@see ViewCacheCommand}).
 *
 * Vanilla `find()` resolves a view name to its absolute path by stat-walking `paths × extensions`
 * (`file_exists` syscalls) the first time each name is seen in a process; the per-process `$views`
 * memo covers in-process repeats, but it is rebuilt every FPM process and — critically — a
 * resolution MISS is never memoized (it throws), so dynamic/`@include($var)`/`<x-dynamic-component>`
 * names re-stat on every render forever. The eager index, built at deploy time from the same
 * resolver, makes a known name a single array hit from request one and survives the per-request
 * `flush()` (it lives in its own property, not `$views`).
 *
 * Byte-identical: each index entry is the exact path the live finder returned at build time
 * (the command parity-probes `find()`), so a hit equals what vanilla would compute. Any name not in
 * the index (added/dynamic/non-blade) falls through to the live `parent::find()` unchanged.
 */
class FileViewFinder extends BaseFinder
{
    /**
     * Eager view-resolution index: normalized view name => absolute source path.
     *
     * @var array<string, string>
     */
    protected array $greaseViewIndex = [];

    /**
     * Build a greased finder from an existing one, copying its full state (files, paths, located
     * views, namespace hints, extensions) so it's a transparent drop-in across framework versions.
     */
    public static function fromBase(BaseFinder $base): static
    {
        $new = (new ReflectionClass(static::class))->newInstanceWithoutConstructor();

        foreach ((new ReflectionClass(BaseFinder::class))->getProperties() as $property) {
            if ($property->isStatic() || ! $property->isInitialized($base)) {
                continue;
            }

            $property->setValue($new, $property->getValue($base));
        }

        return $new;
    }

    /**
     * Seed the eager index. Live-located views ($views) are still consulted by parent::find();
     * the index only short-circuits the stat-walk for the names it knows.
     *
     * @param  array<string, string>  $index
     */
    public function useGreaseViewIndex(array $index): void
    {
        $this->greaseViewIndex += $index;
    }

    /** {@inheritDoc} */
    public function find($view)
    {
        return $this->greaseViewIndex[$view] ?? parent::find($view);
    }
}
