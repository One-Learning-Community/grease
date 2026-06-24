<?php

namespace Grease\Tests\Fixtures\Routing;

use Closure;
use Illuminate\Http\Request;

/**
 * A trivial route middleware used by the boot-parity test: it stamps a header so the served
 * response proves the middleware actually ran through the (greased or vanilla) resolve path.
 */
class TagMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        $response->headers->set('X-Grease-Mw', 'ran');

        return $response;
    }
}
