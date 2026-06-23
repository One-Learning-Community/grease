<?php

namespace Grease\Http;

use Illuminate\Http\Request as BaseRequest;

/**
 * Greased HTTP request with per-instance input memoization.
 *
 * Behaviour-identical to {@see \Illuminate\Http\Request}; `input()`/`all()`/`isJson()`
 * and everything that funnels through them (`__get`, `has`, `only`, `filled`, …) read a
 * memoized merged map instead of rebuilding it per call. See {@see MemoizesRequestInput}.
 *
 * Like the container, the request is created by the bootstrap before any provider runs,
 * so opt in at the capture site — in `public/index.php`:
 *
 *     $request = \Grease\Http\Request::capture();
 *
 * `capture()` builds via `static`, so the greased class propagates through the request
 * lifecycle (the container binds this instance as `request`).
 */
class Request extends BaseRequest
{
    use MemoizesRequestInput;
}
