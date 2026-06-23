<?php

namespace Grease\View;

use Illuminate\Contracts\View\View;
use Illuminate\Support\LazyCollection;
use Illuminate\View\Factory as BaseFactory;
use ReflectionClass;

/**
 * A drop-in faster view Factory — overrides the `@foreach` `$loop` bookkeeping
 * (`ManagesLoops`), which the profile puts at ~a third of a loop-heavy render and is
 * the same on every `@foreach` in the app. Bind it as the `view` singleton (see
 * {@see GreaseViewServiceProvider}); behaviour stays identical to vanilla.
 *
 * Vanilla's per-iteration cost is two things: `incrementLoopIndices()` rebuilds the
 * 10-key loop-state array with an `array_merge` *every iteration* (the single biggest
 * line in a loop render — ~25% self), and `getLastLoop()`/`addLoop()` reach the top of
 * the stack through `Arr::last()` (a closure-defaulting helper) when a bare `end()` /
 * index does. This subclass keeps the exact same loop-state shape and the exact same
 * fresh `(object)` snapshot semantics — so `$loop`, `$loop->parent`, and any snapshot a
 * template stashes are byte-for-byte what vanilla produces — while updating the state in
 * place instead of merging. The micro-A/B (benchmarks/loop_microbench.php) puts the safe
 * rewrite at −40% of the machinery; the page-table macro gates the rendered output.
 */
class Factory extends BaseFactory
{
    /**
     * Build a greased factory that takes over from an existing one, copying its full
     * state (engines, finder, events, container, shared data, …) so it's a transparent
     * drop-in. Reflection-clones every base property rather than naming them, so it
     * survives Factory gaining state across framework versions.
     */
    public static function fromBase(BaseFactory $base): static
    {
        $new = (new ReflectionClass(static::class))->newInstanceWithoutConstructor();

        foreach ((new ReflectionClass(BaseFactory::class))->getProperties() as $property) {
            if ($property->isStatic() || ! $property->isInitialized($base)) {
                continue;
            }

            $property->setValue($new, $property->getValue($base));
        }

        // The base shared *itself* as `__env` in its constructor; repoint that at the
        // greased instance so compiled views call our loop methods through `$__env`.
        $new->share('__env', $new);

        return $new;
    }

    /**
     * {@inheritDoc}
     *
     * Same loop-state array as the parent, with `Arr::last()` replaced by a direct
     * top-of-stack read (no closure-defaulting helper).
     */
    public function addLoop($data)
    {
        $length = is_countable($data) && ! $data instanceof LazyCollection
            ? count($data)
            : null;

        $parent = $this->loopsStack
            ? $this->loopsStack[count($this->loopsStack) - 1]
            : null;

        $this->loopsStack[] = [
            'iteration' => 0,
            'index' => 0,
            'remaining' => $length ?? null,
            'count' => $length,
            'first' => true,
            'last' => isset($length) ? $length == 1 : null,
            'odd' => false,
            'even' => true,
            'depth' => count($this->loopsStack) + 1,
            'parent' => $parent ? (object) $parent : null,
        ];
    }

    /**
     * {@inheritDoc}
     *
     * Updates the top loop state in place (by reference) instead of rebuilding it with
     * `array_merge` every iteration. Every field ends identical to the parent's merge.
     */
    public function incrementLoopIndices()
    {
        $loop = &$this->loopsStack[count($this->loopsStack) - 1];

        $iteration = $loop['iteration'];

        $loop['iteration'] = $iteration + 1;
        $loop['index'] = $iteration;
        $loop['first'] = $iteration == 0;
        $loop['odd'] = ! $loop['odd'];
        $loop['even'] = ! $loop['even'];

        if (isset($loop['count'])) {
            $loop['remaining'] = $loop['remaining'] - 1;
            $loop['last'] = $iteration == $loop['count'] - 1;
        }
    }

    /**
     * {@inheritDoc}
     *
     * Direct top-of-stack read instead of `Arr::last()`. Still returns a *fresh*
     * `(object)` snapshot every call, so a template that stashes `$loop` across
     * iterations sees distinct objects exactly as it does under vanilla.
     */
    public function getLastLoop()
    {
        if (! $this->loopsStack) {
            return null;
        }

        return (object) $this->loopsStack[count($this->loopsStack) - 1];
    }

    /**
     * {@inheritDoc}
     *
     * `@yield('content')` hands the *whole page body* to `yieldContent`, which vanilla
     * runs through THREE full-content `str_replace` passes every render — ~29% of a layout
     * render's self-time. All three exist only to resolve the `@parent` directive:
     *   1. `@@parent`            → `--parent--holder--`   (protect a literal)
     *   2. `<parent placeholder>` → ``                    (strip an unfilled @parent)
     *   3. `--parent--holder--`   → `@parent`             (restore the literal)
     *
     * The net effect is a single substitution over three mutually non-overlapping markers:
     * `@@parent`→`@parent`, `--parent--holder--`→`@parent`, placeholder→``. Because the
     * markers share no prefixes and the replacement (`@parent`) matches none of them,
     * neither the three sequential passes nor a single pass re-scan their own output — so
     * one `preg_replace_callback` over the alternation is byte-identical to all three, and
     * PCRE's literal-alternation scan handles the (overwhelming) no-match case in a single,
     * far cheaper pass (micro: −87% vs the three `str_replace`; `strtr` was +47% — a trap).
     *
     * Non-string content (a `View` default with no matching section) defers to the parent,
     * preserving its exact behaviour (including the `str_replace`-on-non-string error).
     */
    public function yieldContent($section, $default = '')
    {
        if (! isset($this->sections[$section]) && $default instanceof View) {
            return parent::yieldContent($section, $default);
        }

        $sectionContent = $this->sections[$section] ?? e($default);

        $placeholder = static::parentPlaceholder($section);

        return preg_replace_callback(
            '/@@parent|--parent--holder--|'.preg_quote($placeholder, '/').'/',
            static fn ($m) => $m[0] === $placeholder ? '' : '@parent',
            $sectionContent
        );
    }
}
