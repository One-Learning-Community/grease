<?php

namespace Grease\Http;

use Illuminate\Support\Arr;

/**
 * Grease HTTP tier — per-instance input memoization.
 *
 * Vanilla {@see \Illuminate\Http\Concerns\InteractsWithInput::input()} rebuilds the
 * merged input map — `$this->getInputSource()->all() + $this->query->all()` — on *every*
 * call, and `all()` wraps that in a fresh `array_replace_recursive(..., allFiles())` every
 * call. Nearly every accessor funnels through them: `__get`, `offsetGet`, `offsetExists`,
 * `toArray`, and most of `InteractsWithData` (`has`/`only`/`except`/`filled`/`whenHas`…),
 * so a single request rebuilds the same array 10–30×. `isJson()` (called by
 * `getInputSource()` on every input read) re-scans the content-type each time too.
 *
 * The merged map is stable for the life of the request once middleware has run, so it's
 * memoized per-instance and invalidated in every Laravel-level mutator. `isJson()` is a
 * pure function of the (immutable) content-type header, so it's memoized for the life of
 * the instance.
 *
 * Carve-out (documented, mirrors the Eloquent `$casts`-in-constructor caveat): **direct
 * bag mutation** — `$request->request->set(...)`, `$request->query->add(...)` — is not
 * cheaply observable and is unsupported after the first input read on a greased request.
 * Use the Laravel-level mutators (`merge`/`replace`/`offsetSet`) instead, which the memo
 * tracks. Likewise `setMethod()` after a read (switching the GET/HEAD input source) is
 * unsupported.
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

    /** Memoized content-type classification (immutable per request). */
    protected ?bool $greaseIsJson = null;

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
        return $this->greaseIsJson ??= parent::isJson();
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
     * Drop the memoized input maps. `isJson` is not flushed — the content-type header is
     * immutable for the life of the request.
     */
    protected function flushGreaseInput(): void
    {
        $this->greaseInputBase = null;
        $this->greaseAllBase = null;
    }
}
