<?php

namespace Grease\Database\Eloquent;

use Closure;
use Illuminate\Database\Eloquent\Builder as BaseBuilder;

/**
 * An Eloquent builder that memoizes the `__call` dispatch verdict.
 *
 * `Eloquent\Builder` is a thin model-aware skin over the base query builder: it defines
 * only the model-flavored methods and lets every other verb (`where`, `orderBy`, `select`,
 * `whereIn`, `count`, …) fall through `__call`, which re-resolves, on EVERY call, what the
 * name means — a `hasNamedScope` probe (`method_exists('scope'.ucfirst(...))` + attribute
 * scan) and an `in_array(strtolower($method), $this->passthru)` linear scan over a 32-element
 * list. For a given (model class, method) that verdict is immutable — named scopes and the
 * passthru list are fixed per process — so it is computed once and cached.
 *
 * Behaviour-identical: the order is exactly vanilla's. The two MUTABLE arms stay live —
 * local + global macros are re-probed first on every call, so a memoized verdict can never
 * shadow a macro registered later. Only the immutable scope/passthru/forward decision is
 * memoized, keyed by the concrete model class (STI-safe). The verdict is always a non-null
 * string, so `??=` is safe (no null-memo trap), and it never needs invalidation (class-pure,
 * the class-attributes carve-out precedent).
 */
class Builder extends BaseBuilder
{
    /**
     * Memoized per-(model class, method) dispatch verdict: 'scope' | 'passthru' | 'forward'.
     *
     * @var array<class-string, array<string, string>>
     */
    protected static array $greaseCallVerdicts = [];

    /**
     * Dynamically handle calls into the query instance — macros live, the rest memoized.
     *
     * @param  string  $method
     * @param  array  $parameters
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        if ($method === 'macro') {
            $this->localMacros[$parameters[0]] = $parameters[1];

            return;
        }

        if ($this->hasMacro($method)) {
            array_unshift($parameters, $this);

            return $this->localMacros[$method](...$parameters);
        }

        if (static::hasGlobalMacro($method)) {
            $callable = static::$macros[$method];

            if ($callable instanceof Closure) {
                $callable = $callable->bindTo($this, static::class);
            }

            return $callable(...$parameters);
        }

        $verdict = static::$greaseCallVerdicts[$this->model::class][$method]
            ??= $this->greaseResolveCallVerdict($method);

        if ($verdict === 'scope') {
            return $this->callNamedScope($method, $parameters);
        }

        if ($verdict === 'passthru') {
            return $this->toBase()->{$method}(...$parameters);
        }

        $this->forwardCallTo($this->query, $method, $parameters);

        return $this;
    }

    /**
     * Resolve the immutable dispatch verdict for a method name, in vanilla's exact order
     * (named scope wins over passthru, which wins over a plain forward).
     */
    protected function greaseResolveCallVerdict(string $method): string
    {
        if ($this->hasNamedScope($method)) {
            return 'scope';
        }

        if (in_array(strtolower($method), $this->passthru)) {
            return 'passthru';
        }

        return 'forward';
    }
}
