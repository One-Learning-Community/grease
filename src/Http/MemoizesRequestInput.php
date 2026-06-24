<?php

namespace Grease\Http;

use Illuminate\Http\Concerns\InteractsWithInput;
use Illuminate\Support\Arr;

/**
 * Grease HTTP tier — per-instance input memoization.
 *
 * Vanilla {@see InteractsWithInput::input()} rebuilds the
 * merged input map — `$this->getInputSource()->all() + $this->query->all()` — on *every*
 * call, and `all()` wraps that in a fresh `array_replace_recursive(..., allFiles())` every
 * call. Nearly every accessor funnels through them: `__get`, `offsetGet`, `offsetExists`,
 * `toArray`, and most of `InteractsWithData` (`has`/`only`/`except`/`filled`/`whenHas`…),
 * so a single request rebuilds the same array 10–30×. `isJson()` (called by
 * `getInputSource()` on every input read) re-scans the content-type each time too.
 *
 * The merged map is stable for the life of the request once middleware has run, so it's
 * memoized per-instance and invalidated in every Laravel-level mutator. `isJson()` is NOT
 * memoized — the content-type header can change after construction (content-negotiation
 * middleware), and a memo there would freeze a stale answer; it just delegates to vanilla,
 * which is a cheap header scan and, because the input base above is already memoized,
 * runs at most once per input read regardless.
 *
 * Every observable mutation path is tracked. The value mutators (`merge`/`mergeIfMissing`/
 * `replace`/`offsetSet`/`offsetUnset`/`setJson`) flush the base arrays. The lifecycle
 * paths that swap whole bags — `__clone()` (and therefore `duplicate()`), `initialize()`,
 * and `setMethod()` (which rewrites `REQUEST_METHOD`, flipping `getInputSource()` between
 * the query and request bags) — flush as well; `__clone`/`initialize` also drop the
 * `isJson` memo since they can change the content-type.
 *
 * The ONE carve-out (documented, mirrors the Eloquent `$casts`-in-constructor caveat):
 * **direct mutation of an input-source bag** — `$request->query->add(...)`,
 * `$request->request->set(...)`, `$request->json()->set(...)` — bypasses every method and
 * is not cheaply observable, so it's unsupported after the first input read on a greased
 * request. Use the Laravel-level mutators instead, which the memo tracks.
 *
 * Scope of the carve-out (audited per bag — only the input source matters):
 *   - `query` / `request` / `json` — feed `input()`/`all()`. Direct mutation = carve-out.
 *   - `attributes` — route params / middleware context (`$request->attributes->set(...)`).
 *     Read via `attributes->get()` / the deprecated `get()` / `route()`, NEVER by
 *     `input()`/`all()`/`__get`. **Direct mutation is fully safe** — outside the memo.
 *   - `cookies` — not read by `input()`/`all()`. Safe to mutate directly.
 *   - `files` — feeds `all()` via `allFiles()`, but vanilla already caches that
 *     (`$convertedFiles`), so a post-read file mutation is stale in vanilla too — parity
 *     holds, no new caveat.
 *   - `server` / `headers` — `setMethod()` flushes the input base; `isJson()` reads the
 *     content-type live (not memoized), so a header change is reflected. The one residue:
 *     flipping the content-type *after* an input read, which would switch `input()`'s
 *     source bag (JSON vs form), is in the same carve-out as direct bag mutation — exotic
 *     and outside the supported surface.
 *
 * Why this can't be locked down (it was considered): the obvious guard — making the
 * `query`/`request` bag properties private here — is impossible *and* would miss the mark.
 *   1. PHP forbids narrowing an inherited property's visibility ("Access level to
 *      Child::$query must be public (as in class Base)"); these are PHP 8.4 hooked
 *      properties, so even asymmetric `public private(set)` is rejected. And they're read
 *      publicly by Symfony itself (`getInputSource()` reads `$this->query`) and across the
 *      ecosystem (`$request->query->get(...)`), so hiding them would break the framework.
 *   2. Even if it were possible, visibility wouldn't help: the cache-averting call is a
 *      *method on the bag object* (`->query->set()`), reached by *reading* the property.
 *      Visibility governs reading/reassignment, not method calls on the held object.
 * The only true interception — a notify-on-mutate `InputBag` subclass — can't be
 * guaranteed (`duplicate()` hardcodes `new InputBag(...)` via reflection), so it would be
 * leaky. An honest, narrow carve-out is the correct boundary; real apps mutate input via
 * `merge()`/`$request['k'] = …`, not the raw Symfony bags.
 */
trait MemoizesRequestInput
{
    /**
     * Memoized `getInputSource()->all() + query->all()` — the base for `input()`.
     * `null` = unset; `[]` is a valid (empty-input) cached value.
     */
    protected ?array $greaseInputBase = null;

    /** Memoized `array_replace_recursive(input(), allFiles())` — the base for `all()`. */
    protected ?array $greaseAllBase = null;

    /**
     * {@inheritDoc}
     */
    public function input($key = null, $default = null)
    {
        $this->greaseInputBase ??= $this->getInputSource()->all() + $this->query->all();

        return data_get($this->greaseInputBase, $key, $default);
    }

    /**
     * {@inheritDoc}
     */
    public function all($keys = null)
    {
        $input = $this->greaseAllBase ??= array_replace_recursive($this->input(), $this->allFiles());

        if (! $keys) {
            return $input;
        }

        $results = [];

        foreach (is_array($keys) ? $keys : func_get_args() as $key) {
            Arr::set($results, $key, Arr::get($input, $key));
        }

        return $results;
    }

    /**
     * {@inheritDoc}
     */
    public function isJson()
    {
        // Read live, not memoized: the content-type header can change after
        // construction (content-negotiation middleware), and the input-base memo
        // already keeps this off the per-read hot path.
        return parent::isJson();
    }

    /**
     * {@inheritDoc}
     */
    public function merge(array $input)
    {
        $result = parent::merge($input);

        $this->flushGreaseInput();

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function replace(array $input)
    {
        $result = parent::replace($input);

        $this->flushGreaseInput();

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function setJson($json)
    {
        $result = parent::setJson($json);

        $this->flushGreaseInput();

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function offsetSet($offset, $value): void
    {
        parent::offsetSet($offset, $value);

        $this->flushGreaseInput();
    }

    /**
     * {@inheritDoc}
     */
    public function offsetUnset($offset): void
    {
        parent::offsetUnset($offset);

        $this->flushGreaseInput();
    }

    /**
     * {@inheritDoc}
     *
     * Rewrites REQUEST_METHOD, which `getRealMethod()` reads and `getInputSource()` uses
     * to choose the query vs request bag — so the merged base can change. (`isJson` is
     * unaffected: the content-type header doesn't change.)
     */
    public function setMethod(string $method): void
    {
        parent::setMethod($method);

        $this->flushGreaseInput();
    }

    /**
     * {@inheritDoc}
     *
     * Re-seeds every bag. Drop the full input memo (isJson() is read live, so nothing to reset).
     */
    public function initialize(array $query = [], array $request = [], array $attributes = [], array $cookies = [], array $files = [], array $server = [], $content = null): void
    {
        parent::initialize($query, $request, $attributes, $cookies, $files, $server, $content);

        $this->flushGreaseInput();
    }

    /**
     * Clone clones every bag (and `duplicate()` is clone-then-swap-bags). The copied memo
     * would describe the pre-clone bags, so drop it.
     */
    public function __clone()
    {
        parent::__clone();

        $this->flushGreaseInput();
    }

    /**
     * Drop the memoized input maps. Used by the value mutators and lifecycle paths.
     */
    protected function flushGreaseInput(): void
    {
        $this->greaseInputBase = null;
        $this->greaseAllBase = null;
    }
}
