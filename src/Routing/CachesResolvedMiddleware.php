<?php

namespace Grease\Routing;

use Illuminate\Routing\Router;

/**
 * Per-instance cache of {@see Router::resolveMiddleware()}'s exact
 * sorted output, keyed by the literal (gathered, excluded) middleware-name arrays.
 *
 * For the matched route the resolve+sort runs ~2×/request (dispatch + terminate), and each
 * run repeats the same work: group/alias expansion via `MiddlewareNameResolver`, a
 * `map`/`flatten`/`values` Collection chain, and a `SortedMiddleware` pass that calls
 * `class_implements()` + `class_parents()` on every middleware string. That output is a
 * pure function of (those names, the process-constant alias/group/priority maps) — the raw
 * name list is already cached on the route (`Route::$computedMiddleware`), but the
 * resolve+sort is not. We cache the returned array *verbatim* (order is load-bearing, so no
 * reordering logic is touched) and hand back the cached array on a repeat signature.
 *
 * Marginal in PHP-FPM (≤2×/request, low-µs) but a real per-request win nonetheless — the
 * terminate-pass resolve becomes a hit. The full payoff is under Octane: the Router
 * singleton persists, so every route signature the worker has ever seen stays resolved.
 *
 * Invalidation: every Router method that mutates an input map (`aliasMiddleware`,
 * `middlewareGroup`, `prependMiddlewareToGroup`, `pushMiddlewareToGroup`,
 * `removeMiddlewareFromGroup`, `flushMiddlewareGroups`) flushes the whole cache. The
 * standard `Kernel::syncMiddlewareToRouter()` funnels *all* runtime middleware changes —
 * priority included — back through `aliasMiddleware`/`middlewareGroup`, so those changes
 * flush too. The one carve-out is assigning `$router->middlewarePriority` / `->middleware` /
 * `->middlewareGroups` *directly* after the first resolve without going through the
 * registration methods; that is not observable here (the request/config direct-mutation
 * precedent) and never happens on the normal Kernel path.
 */
trait CachesResolvedMiddleware
{
    /**
     * Resolved+sorted middleware arrays, keyed by their (gathered, excluded) signature.
     *
     * @var array<string, array>
     */
    protected $greaseResolvedMiddleware = [];

    /**
     * Resolve a flat array of middleware classes — cached by the literal input signature.
     *
     * @return array
     */
    public function resolveMiddleware(array $middleware, array $excluded = [])
    {
        $key = static::greaseMiddlewareSignature($middleware, $excluded);

        if ($key !== null && array_key_exists($key, $this->greaseResolvedMiddleware)) {
            return $this->greaseResolvedMiddleware[$key];
        }

        $resolved = parent::resolveMiddleware($middleware, $excluded);

        if ($key !== null) {
            $this->greaseResolvedMiddleware[$key] = $resolved;
        }

        return $resolved;
    }

    /**
     * Build a stable cache key from the input arrays, or null to defer (no caching).
     *
     * Only all-string inputs are cacheable: a closure or object in the list (inline
     * middleware, or an alias mapped to a closure) has no stable identity to key on, so we
     * fall through to a live `parent::resolveMiddleware()` for that signature. Public + static
     * so the eager `grease:route-cache` builder keys the on-disk index identically — a seeded
     * entry is only ever served on an exact signature match.
     */
    public static function greaseMiddlewareSignature(array $middleware, array $excluded): ?string
    {
        $key = '';

        foreach ($middleware as $name) {
            if (! is_string($name)) {
                return null;
            }

            $key .= $name."\n";
        }

        $key .= "\x1f";

        foreach ($excluded as $name) {
            if (! is_string($name)) {
                return null;
            }

            $key .= $name."\n";
        }

        return $key;
    }

    /**
     * Pre-seed the resolve cache with an eager `grease:route-cache` index (signature => resolved
     * sorted list). Live-resolved entries are never overwritten (`+=`), and because the index is
     * keyed by {@see greaseMiddlewareSignature()} it is served only on an exact match — so a
     * route whose runtime signature differs (dynamic middleware) simply misses and resolves live.
     * A later map mutation flushes the whole cache, seeded entries included.
     *
     * @param  array<string, array>  $index
     */
    public function useGreaseRouteMiddlewareCache(array $index): void
    {
        $this->greaseResolvedMiddleware += $index;
    }

    /**
     * Flush the resolve cache — any change to the alias/group maps can change the output.
     */
    protected function flushGreaseResolvedMiddleware(): void
    {
        $this->greaseResolvedMiddleware = [];
    }

    /** {@inheritdoc} */
    public function aliasMiddleware($name, $class)
    {
        $this->flushGreaseResolvedMiddleware();

        return parent::aliasMiddleware($name, $class);
    }

    /** {@inheritdoc} */
    public function middlewareGroup($name, array $middleware)
    {
        $this->flushGreaseResolvedMiddleware();

        return parent::middlewareGroup($name, $middleware);
    }

    /** {@inheritdoc} */
    public function prependMiddlewareToGroup($group, $middleware)
    {
        $this->flushGreaseResolvedMiddleware();

        return parent::prependMiddlewareToGroup($group, $middleware);
    }

    /** {@inheritdoc} */
    public function pushMiddlewareToGroup($group, $middleware)
    {
        $this->flushGreaseResolvedMiddleware();

        return parent::pushMiddlewareToGroup($group, $middleware);
    }

    /** {@inheritdoc} */
    public function removeMiddlewareFromGroup($group, $middleware)
    {
        $this->flushGreaseResolvedMiddleware();

        return parent::removeMiddlewareFromGroup($group, $middleware);
    }

    /** {@inheritdoc} */
    public function flushMiddlewareGroups()
    {
        $this->flushGreaseResolvedMiddleware();

        return parent::flushMiddlewareGroups();
    }
}
