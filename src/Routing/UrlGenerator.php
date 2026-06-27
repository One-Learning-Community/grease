<?php

namespace Grease\Routing;

use Illuminate\Contracts\Routing\UrlRoutable;
use Illuminate\Routing\RouteCollectionInterface;
use Illuminate\Routing\UrlGenerator as BaseUrlGenerator;

/**
 * Greased URL generator — an eager-index fast path for `route()` (named-route URLs).
 *
 * Vanilla `route('api.posts.show', $post)` runs the full {@see \Illuminate\Routing\
 * RouteUrlGenerator::to()} assembly *every call*: `formatParameters` + `replaceRouteParameters`
 * + `replaceNamedParameters` + `addQueryString` + the optional-parameter walk, plus a thick
 * `Arr`/`Collection` layer underneath. None of that re-runs Symfony's route compiler —
 * `Route::$compiled` is cached and persisted through `route:cache` — so the compile is already
 * free; the residual cost is pure per-call string assembly. On an API-Resource payload that
 * emits a handful of links per row across hundreds of rows, that assembly is paid thousands of
 * times for a URI shape that never changes.
 *
 * The shape of a simple route URL *is* a class-pure fact: split `api/posts/{post}/comments`
 * into static segments `['api/posts/', '/comments']` and an ordered param list `['post']` once,
 * then every URL is `segment . value . segment . …`, encoded. This tier caches that
 * `[segments, params]` entry per route name (lazily on first use, or pre-seeded from the
 * `grease:route-cache` index — see {@see useGreaseRouteUrlIndex()}) and replays it with a tight
 * concat, skipping the whole assembly pipeline. **−93% per call** (micro), and because the
 * assembly cost is fixed while the model tiers shrink everything else, **~−31% of an
 * already-greased API-Resource response** (`benchmarks/url_realworld.php`).
 *
 * **Byte-identical or defer.** The fast path fires ONLY when the result is provably the exact
 * string vanilla would build; anything else falls through to `parent::toRoute()`:
 *   - Not indexable (compile-time, {@see greaseCompileEntry()}): a domain route, an optional
 *     `{param?}` or scoped `{param:field}` binding, or a route with its own `$defaults`.
 *   - Not fast-pathable (call-time): extra params (→ query string), a missing/positional-arity
 *     mismatch (→ vanilla's `UrlGenerationException`), a non-scalar value, a value that would
 *     introduce a literal `{…}` (vanilla treats that as a missing parameter and throws), a
 *     subdirectory app for a *relative* URL (non-empty `getBaseUrl()`), or any `URL::defaults()`
 *     / `formatHostUsing()` / `formatPathUsing()` customization in effect.
 *
 * Absolute (the `route()` default) and relative are both accelerated: the absolute root/scheme
 * is taken from vanilla's own already-memoized `formatRoot()`/`formatScheme()` (which honor
 * `forceScheme`/`forceRootUrl` and per-route `secure()`), so only the parameter assembly is
 * skipped. Encoding reuses the route generator's public `$dontEncode` map verbatim.
 *
 * Wired in by {@see GreaseRoutingServiceProvider} (a `url` singleton rebind — unlike the
 * kernel-injected router, the generator is resolved lazily, so a provider swap is in time).
 */
class UrlGenerator extends BaseUrlGenerator
{
    /**
     * Per-name route shape: `['segments' => string[], 'params' => string[]]`, or `false` when
     * the route is not safely indexable. `array_key_exists` distinguishes "cached false" from
     * "unseen" — the null-memo trap the model tiers document.
     *
     * @var array<string, array{segments: array<int, string>, params: array<int, string>}|false>
     */
    protected array $greaseRouteUrlIndex = [];

    /** Memoized copy of the route generator's public `$dontEncode` map. */
    protected ?array $greaseDontEncode = null;

    /** Set once `URL::defaults()` is used — those inject params the concat cannot model. */
    protected bool $greaseUrlDefaultsSet = false;

    /**
     * {@inheritdoc}
     *
     * Fast-path a named-route URL from the eager index when the result is provably identical to
     * vanilla; otherwise defer. The guard chain is ordered cheapest-first.
     */
    public function toRoute($route, $parameters, $absolute)
    {
        if (is_object($route)
            && ! $this->greaseUrlDefaultsSet
            && $this->formatHostUsing === null
            && $this->formatPathUsing === null) {
            $name = $route->getName();

            if ($name !== null) {
                $entry = $this->greaseRouteEntry($name, $route);

                if ($entry !== false) {
                    $fast = $this->greaseFastToRoute($route, $entry, $parameters, (bool) $absolute);

                    if ($fast !== null) {
                        return $fast;
                    }
                }
            }
        }

        return parent::toRoute($route, $parameters, $absolute);
    }

    /**
     * Fetch the cached shape for a route name, compiling (and memoizing) it on first use.
     *
     * @return array{segments: array<int, string>, params: array<int, string>}|false
     */
    protected function greaseRouteEntry(string $name, $route)
    {
        if (array_key_exists($name, $this->greaseRouteUrlIndex)) {
            return $this->greaseRouteUrlIndex[$name];
        }

        return $this->greaseRouteUrlIndex[$name] = static::greaseCompileEntry($route);
    }

    /**
     * Build the static `[segments, params]` shape for a route, or `false` if it is not safely
     * indexable. Pure function of the route definition — public+static so the
     * `grease:route-cache` builder produces byte-identical entries to the lazy path.
     *
     * @return array{segments: array<int, string>, params: array<int, string>}|false
     */
    public static function greaseCompileEntry($route)
    {
        if ($route->getDomain() !== null) {
            return false; // domain routes assemble a host — defer
        }

        $uri = $route->uri();

        // Optional `{param?}` or scoped `{param:field}` bindings change replacement semantics.
        if (preg_match('/\{[^}]*[?:]/', $uri)) {
            return false;
        }

        if ($route->getOptionalParameterNames() || ! empty($route->defaults)) {
            return false; // route-level defaults inject parameter values the concat cannot see
        }

        $segments = preg_split('/\{[^}]+\}/', $uri);
        preg_match_all('/\{([^}]+)\}/', $uri, $matches);

        return ['segments' => $segments, 'params' => $matches[1]];
    }

    /**
     * Assemble the URL from the cached shape, or return null to defer to vanilla.
     */
    protected function greaseFastToRoute($route, array $entry, $parameters, bool $absolute): ?string
    {
        $names = $entry['params'];
        $segments = $entry['segments'];

        $params = is_array($parameters) ? $parameters : [$parameters];

        // Arity must match exactly: a shortfall is vanilla's UrlGenerationException, a surplus
        // becomes a query string. Either way, defer.
        if (count($params) !== count($names)) {
            return null;
        }

        $values = [];

        if ($names !== []) {
            if (array_is_list($params)) {
                $values = $params;
            } else {
                foreach ($names as $name) {
                    if (! array_key_exists($name, $params)) {
                        return null;
                    }

                    $values[] = $params[$name];
                }
            }
        }

        $path = $segments[0];

        foreach ($values as $i => $value) {
            if ($value instanceof UrlRoutable) {
                $value = $value->getRouteKey();
            }

            if (! is_string($value) && ! is_int($value)) {
                return null; // null/bool/float/array have distinct vanilla semantics
            }

            $path .= $value.$segments[$i + 1];
        }

        // A value that injects a literal `{…}` is a "missing parameter" to vanilla → it throws.
        if (str_contains($path, '{') && preg_match('/\{.*?\}/', $path)) {
            return null;
        }

        $dontEncode = $this->greaseDontEncode ??= $this->routeUrl()->dontEncode;

        if ($absolute) {
            $scheme = $route->httpOnly() ? 'http://' : ($route->httpsOnly() ? 'https://' : $this->formatScheme());

            return strtr(rawurlencode(trim($this->formatRoot($scheme).'/'.trim($path, '/'), '/')), $dontEncode);
        }

        // Relative: vanilla strips the root and the request base path. The simple form below is
        // byte-identical only at the document root; a subdirectory app defers.
        if ($this->request->getBaseUrl() !== '') {
            return null;
        }

        return '/'.ltrim(strtr(rawurlencode($path), $dontEncode), '/');
    }

    /**
     * Pre-seed the per-name index from the eager `grease:route-cache` artifact. Lazily compiled
     * entries are never overwritten (`+=`); a seeded name is served only on an exact match, so a
     * route absent from the index simply compiles on first use.
     *
     * @param  array<string, array{segments: array<int, string>, params: array<int, string>}>  $index
     */
    public function useGreaseRouteUrlIndex(array $index): void
    {
        $this->greaseRouteUrlIndex += $index;
    }

    /** {@inheritdoc} — URL defaults inject parameters the fast path cannot model; disable it. */
    public function defaults(array $defaults)
    {
        if ($defaults !== []) {
            $this->greaseUrlDefaultsSet = true;
        }

        return parent::defaults($defaults);
    }

    /** {@inheritdoc} — the route set changed (e.g. cached routes rebound); drop stale shapes. */
    public function setRoutes(RouteCollectionInterface $routes)
    {
        $this->greaseRouteUrlIndex = [];

        return parent::setRoutes($routes);
    }
}
