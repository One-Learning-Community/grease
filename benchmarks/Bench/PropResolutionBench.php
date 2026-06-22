<?php

namespace Grease\Bench;

use Grease\Bench\Support\BenchAttributeBag;
use Illuminate\Support\Str;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\RetryThreshold;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Warmup;

/**
 * The full per-render prop/attribute resolution block that Blade's `@props` compiles
 * to (CompilesComponents::compileProps), modelled end-to-end so we can size the whole
 * prize, not just the `in_array` lookup. Every component pays this on every render;
 * Livewire re-renders the same components across roundtrips.
 *
 * The emitted block, verbatim, does four things per render:
 *   1. rebuild a flat name list (`extractPropNames`) and partition incoming attributes
 *      with `in_array` (O(props × attributes)), binding props to locals;
 *   2. allocate a *second* `ComponentAttributeBag` from the leftover attributes;
 *   3. fill defaults for props not passed (`array_filter(..., is_string, ...)`);
 *   4. snapshot the ENTIRE scope with `get_defined_vars()` and unset any local whose
 *      name collides with a pass-through attribute.
 *
 * Three arms, each producing the identical partition + same surviving locals, isolating
 * where the cost is:
 *   - **FullVanilla**  — the block exactly as emitted.
 *   - **FullHoisted**  — keyed `[name => true]` map built ONCE (the `@props` set is a
 *     compile-time constant) + `isset()`; still does the `get_defined_vars()` cleanup.
 *   - **FullOptimized**— hoisted keyed map AND the scope snapshot replaced by a targeted
 *     `unset($$key)` over the (few) pass-through attributes. No `get_defined_vars()`.
 *
 * Shape = a page's worth of renders (RENDERS), one representative component: ~10 props,
 * ~12 incoming attributes.
 */
#[
    BeforeMethods('setUp'),
    Warmup(2),
    Revs(500),
    Iterations(10),
    RetryThreshold(3),
]
class PropResolutionBench
{
    private const RENDERS = 60;

    /** @var array<string, mixed> the component's `@props` declaration */
    private array $props;

    /** @var array<string, mixed> the attributes the parent passes in */
    private array $attributes;

    /** @var array<string, true> the keyed prop-name map, built once (compile-time constant) */
    private array $hoistedPropNames;

    /** @var array<string, array<string, true>> signature-keyed memo of prop-name maps */
    private array $propMemo = [];

    /** @var int sink that consumes each render's result so it can't be elided */
    private int $sink = 0;

    public function setUp(): void
    {
        $this->props = [
            'type' => 'primary', 'size' => 'md', 'disabled' => false,
            'iconName' => null, 'iconPosition' => 'left', 'href' => null,
            'rounded' => false, 'loading' => false, 'fullWidth' => false,
            'variant' => 'solid',
        ];

        $this->attributes = [
            'type' => 'submit', 'class' => 'btn btn-lg', 'wire:click' => 'save',
            'x-data' => '{open:false}', 'data-testid' => 'cta', 'id' => 'submit-btn',
            'size' => 'lg', 'aria-label' => 'Submit form', 'role' => 'button',
            'tabindex' => '0', 'style' => 'margin-top:4px', 'iconName' => 'check',
        ];

        $this->hoistedPropNames = $this->extractPropNamesKeyed($this->props);
    }

    /** Vanilla: list built fresh, fed to `in_array`. Verbatim from compileProps. */
    private function extractPropNames(array $keys): array
    {
        $props = [];

        foreach ($keys as $key => $default) {
            $key = is_numeric($key) ? $default : $key;
            $props[] = $key;
            $props[] = Str::kebab($key);
        }

        return $props;
    }

    /** Keyed: `[name => true]` set — dedups, enables isset(), one snake() not kebab(). */
    private function extractPropNamesKeyed(array $keys): array
    {
        $props = [];

        foreach ($keys as $key => $default) {
            $key = is_numeric($key) ? $default : $key;
            $props[$key] = true;
            $props[Str::snake($key, '-')] = true;
        }

        return $props;
    }

    /**
     * Signature-memoized keyed map — what a real `Grease\View\Props` helper could do:
     * memoize by the prop-name signature so identical components share one map. Tests
     * whether keying the memo costs back the hoist's savings. (Keyed by `array_keys`
     * here; list-style props would need name resolution in the signature — a parity
     * detail, not a cost one, since they're rare.)
     */
    private function keyedMemo(array $propsDecl): array
    {
        $sig = implode("\0", array_keys($propsDecl));

        return $this->propMemo[$sig] ??= $this->extractPropNamesKeyed($propsDecl);
    }

    /** Keyed map rebuilt every render (no hoist) + targeted unset. The keyed floor. */
    private function fullKeyedRebuilt(BenchAttributeBag $attributes): BenchAttributeBag
    {
        $__newAttributes = [];
        $__propNames = $this->extractPropNamesKeyed($this->props);

        foreach ($attributes->all() as $__key => $__value) {
            if (isset($__propNames[$__key])) {
                $$__key = $$__key ?? $__value;
            } else {
                $__newAttributes[$__key] = $__value;
            }
        }

        $attributes = new BenchAttributeBag($__newAttributes);
        unset($__propNames, $__newAttributes);

        foreach (array_filter($this->props, 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
            $$__key = $$__key ?? $__value;
        }

        foreach ($attributes->all() as $__key => $__value) {
            unset($$__key);
        }

        return $attributes;
    }

    /** Keyed map via the signature memo + targeted unset. The achievable hoist. */
    private function fullHelperMemo(BenchAttributeBag $attributes): BenchAttributeBag
    {
        $__newAttributes = [];
        $__propNames = $this->keyedMemo($this->props);

        foreach ($attributes->all() as $__key => $__value) {
            if (isset($__propNames[$__key])) {
                $$__key = $$__key ?? $__value;
            } else {
                $__newAttributes[$__key] = $__value;
            }
        }

        $attributes = new BenchAttributeBag($__newAttributes);
        unset($__newAttributes);

        foreach (array_filter($this->props, 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
            $$__key = $$__key ?? $__value;
        }

        foreach ($attributes->all() as $__key => $__value) {
            unset($$__key);
        }

        return $attributes;
    }

    /** The block exactly as Blade emits it. */
    private function fullVanilla(BenchAttributeBag $attributes): BenchAttributeBag
    {
        $__newAttributes = [];
        $__propNames = $this->extractPropNames($this->props);

        foreach ($attributes->all() as $__key => $__value) {
            if (in_array($__key, $__propNames)) {
                $$__key = $$__key ?? $__value;
            } else {
                $__newAttributes[$__key] = $__value;
            }
        }

        $attributes = new BenchAttributeBag($__newAttributes);
        unset($__propNames, $__newAttributes);

        foreach (array_filter($this->props, 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
            $$__key = $$__key ?? $__value;
        }

        $__defined_vars = get_defined_vars();

        foreach ($attributes->all() as $__key => $__value) {
            if (array_key_exists($__key, $__defined_vars)) {
                unset($$__key);
            }
        }

        return $attributes;
    }

    /** Keyed map hoisted (built once), but the get_defined_vars() cleanup retained. */
    private function fullHoisted(BenchAttributeBag $attributes): BenchAttributeBag
    {
        $__newAttributes = [];

        foreach ($attributes->all() as $__key => $__value) {
            if (isset($this->hoistedPropNames[$__key])) {
                $$__key = $$__key ?? $__value;
            } else {
                $__newAttributes[$__key] = $__value;
            }
        }

        $attributes = new BenchAttributeBag($__newAttributes);
        unset($__newAttributes);

        foreach (array_filter($this->props, 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
            $$__key = $$__key ?? $__value;
        }

        $__defined_vars = get_defined_vars();

        foreach ($attributes->all() as $__key => $__value) {
            if (array_key_exists($__key, $__defined_vars)) {
                unset($$__key);
            }
        }

        return $attributes;
    }

    /** Hoisted keyed map + targeted unset, no whole-scope snapshot. */
    private function fullOptimized(BenchAttributeBag $attributes): BenchAttributeBag
    {
        $__newAttributes = [];

        foreach ($attributes->all() as $__key => $__value) {
            if (isset($this->hoistedPropNames[$__key])) {
                $$__key = $$__key ?? $__value;
            } else {
                $__newAttributes[$__key] = $__value;
            }
        }

        $attributes = new BenchAttributeBag($__newAttributes);
        unset($__newAttributes);

        foreach (array_filter($this->props, 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
            $$__key = $$__key ?? $__value;
        }

        // The compiler knows it only created prop locals; unset attribute-named locals
        // directly instead of snapshotting the entire scope to find them.
        foreach ($attributes->all() as $__key => $__value) {
            unset($$__key);
        }

        return $attributes;
    }

    public function benchFullVanilla(): void
    {
        $sink = 0;
        $bag = new BenchAttributeBag($this->attributes);

        for ($r = 0; $r < self::RENDERS; $r++) {
            $sink += count($this->fullVanilla($bag)->all());
        }

        $this->sink = $sink;
    }

    public function benchFullHoisted(): void
    {
        $sink = 0;
        $bag = new BenchAttributeBag($this->attributes);

        for ($r = 0; $r < self::RENDERS; $r++) {
            $sink += count($this->fullHoisted($bag)->all());
        }

        $this->sink = $sink;
    }

    public function benchFullOptimized(): void
    {
        $sink = 0;
        $bag = new BenchAttributeBag($this->attributes);

        for ($r = 0; $r < self::RENDERS; $r++) {
            $sink += count($this->fullOptimized($bag)->all());
        }

        $this->sink = $sink;
    }

    public function benchFullKeyedRebuilt(): void
    {
        $sink = 0;
        $bag = new BenchAttributeBag($this->attributes);

        for ($r = 0; $r < self::RENDERS; $r++) {
            $sink += count($this->fullKeyedRebuilt($bag)->all());
        }

        $this->sink = $sink;
    }

    public function benchFullHelperMemo(): void
    {
        $sink = 0;
        $bag = new BenchAttributeBag($this->attributes);

        for ($r = 0; $r < self::RENDERS; $r++) {
            $sink += count($this->fullHelperMemo($bag)->all());
        }

        $this->sink = $sink;
    }
}
