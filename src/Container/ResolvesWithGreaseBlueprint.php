<?php

namespace Grease\Container;

use Closure;
use Illuminate\Container\Util;
use Illuminate\Contracts\Container\SelfBuilding;
use ReflectionClass;
use ReflectionException;

/**
 * Grease container tier — the "constructor blueprint".
 *
 * Vanilla {@see \Illuminate\Container\Container::build()} rebuilds, on *every* resolve
 * of a non-singleton, work that is a pure function of the class name: a fresh
 * `ReflectionClass`, `getConstructor()`, `getParameters()`, and per-parameter
 * `Util::getParameterClassName()` + attribute walks. None of it changes for the life of
 * the process. This trait freezes that into a per-concrete plan and replays it; the
 * runtime-varying parts (contextual bindings, `$with` overrides, default-vs-bound
 * checks, the resolving callbacks) still execute exactly as vanilla, so output stays
 * behaviour-identical.
 *
 * Mirrors the Eloquent blueprint discipline: keyed by the concrete class string,
 * built lazily, never needs invalidation (a class's constructor signature is immutable
 * per process) except on a container `flush()`.
 *
 * Only the common case is fast-pathed (instantiable, non-{@see SelfBuilding}, with a
 * resolvable reflection). Closures, abstracts, self-building types, and missing classes
 * fall through to `parent::build()` untouched (a `null` plan is cached so the
 * classification is paid once).
 */
trait ResolvesWithGreaseBlueprint
{
    /**
     * Per-concrete compiled build plans. `null` marks a class that must defer to
     * `parent::build()` (not instantiable, self-building, or non-existent).
     *
     * @var array<string, array{0: \ReflectionClass, 1: array<int, \ReflectionAttribute>, 2: ?array<int, array>}|null>
     */
    protected array $greaseBuildPlans = [];

    /**
     * {@inheritDoc}
     */
    public function build($concrete)
    {
        // Closures are resolvers, not class names — never plannable. Vanilla handles them.
        if ($concrete instanceof Closure) {
            return parent::build($concrete);
        }

        if (! array_key_exists($concrete, $this->greaseBuildPlans)) {
            $this->greaseBuildPlans[$concrete] = $this->compileGreaseBuildPlan($concrete);
        }

        $plan = $this->greaseBuildPlans[$concrete];

        // Uncertified shapes (abstract / self-building / missing) defer byte-for-byte.
        if ($plan === null) {
            return parent::build($concrete);
        }

        [$reflector, $classAttributes, $params] = $plan;

        // No constructor: instantiate directly, exactly as vanilla's null-constructor branch.
        if ($params === null) {
            $instance = new $concrete;

            if ($classAttributes !== []) {
                $this->fireAfterResolvingAttributeCallbacks($classAttributes, $instance);
            }

            return $instance;
        }

        $this->buildStack[] = $concrete;

        try {
            $instances = $this->resolveGreaseDependencies($params);
        } finally {
            array_pop($this->buildStack);
        }

        $instance = new $concrete(...$instances);

        if ($classAttributes !== []) {
            $this->fireAfterResolvingAttributeCallbacks($classAttributes, $instance);
        }

        return $instance;
    }

    /**
     * Compile (and freeze) the constructor blueprint for a concrete class, or `null`
     * if the class can't take the fast path and must defer to `parent::build()`.
     *
     * @param  string  $concrete
     * @return array{0: \ReflectionClass, 1: array<int, \ReflectionAttribute>, 2: ?array<int, array>}|null
     */
    protected function compileGreaseBuildPlan($concrete)
    {
        try {
            $reflector = new ReflectionClass($concrete);
        } catch (ReflectionException) {
            return null; // let parent throw the canonical BindingResolutionException
        }

        if (! $reflector->isInstantiable()) {
            return null; // parent::notInstantiable(), with the live buildStack
        }

        if (is_a($concrete, SelfBuilding::class, true)) {
            return null; // self-building detection is partly runtime (buildStack)
        }

        $classAttributes = $reflector->getAttributes();
        $constructor = $reflector->getConstructor();

        if ($constructor === null) {
            return [$reflector, $classAttributes, null];
        }

        $params = [];

        foreach ($constructor->getParameters() as $parameter) {
            $params[] = [
                'param' => $parameter,
                'name' => $parameter->name,
                'className' => Util::getParameterClassName($parameter),
                'contextualAttribute' => Util::getContextualAttributeFromDependency($parameter),
                'attributes' => $parameter->getAttributes(),
                'variadic' => $parameter->isVariadic(),
            ];
        }

        return [$reflector, $classAttributes, $params];
    }

    /**
     * Replay the frozen dependency plan. Identical control flow to vanilla
     * {@see \Illuminate\Container\Container::resolveDependencies()} — only the
     * class-pure reflection lookups are read from the plan instead of recomputed.
     *
     * @param  array<int, array>  $params
     * @return array
     */
    protected function resolveGreaseDependencies(array $params)
    {
        $results = [];

        $override = $this->getLastParameterOverride();

        foreach ($params as $plan) {
            // Per-build `$with` override — runtime, never cached.
            if ($override !== [] && array_key_exists($plan['name'], $override)) {
                $results[] = $override[$plan['name']];

                continue;
            }

            $dependency = $plan['param'];
            $result = null;

            if ($plan['contextualAttribute'] !== null) {
                $result = $this->resolveFromAttribute($plan['contextualAttribute'], $dependency);
            }

            $result ??= $plan['className'] === null
                ? $this->resolvePrimitive($dependency)
                : $this->resolveClass($dependency, $plan['className']);

            if ($plan['attributes'] !== []) {
                $this->fireAfterResolvingAttributeCallbacks($plan['attributes'], $result);
            }

            if ($plan['variadic']) {
                $results = array_merge($results, $result);
            } else {
                $results[] = $result;
            }
        }

        return $results;
    }

    /**
     * Drop the blueprint cache when the container is flushed (parity with harnesses
     * that rebuild the container between runs).
     */
    public function flush()
    {
        $this->greaseBuildPlans = [];

        parent::flush();
    }
}
