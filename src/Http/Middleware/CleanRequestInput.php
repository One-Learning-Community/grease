<?php

namespace Grease\Http\Middleware;

use Closure;
use Grease\Support\CompiledPatternSet;
use Illuminate\Foundation\Http\Middleware\TransformsRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * A drop-in replacement for Laravel's `TrimStrings` + `ConvertEmptyStringsToNull`, fused into a
 * single pass.
 *
 * Vanilla runs both middleware, and each (via `TransformsRequest::clean`) recursively walks the
 * whole input tree and rebuilds the bag — so the input is traversed and rebuilt TWICE, and
 * `TrimStrings::transform` re-derives `array_merge($except, $neverTrim)` + runs `Str::is()` on
 * every string leaf. This fuses the two transforms into one walk, compiles the trim-except list
 * once per request into a {@see CompiledPatternSet} (a hash for literal excepts, one merged
 * regex for wildcard excepts) instead of per-leaf `Str::is`, and rebuilds the bag once.
 *
 * Byte-identical to running `TrimStrings` then `ConvertEmptyStringsToNull` (the stack order): a
 * value is trimmed (unless its key is trim-excepted) and then, if it is the empty string,
 * nulled. tests/CleanRequestInputParityTest.php asserts that against the real framework
 * middleware across nested / except / wildcard / empty / non-string inputs.
 *
 * CAVEAT — the one behaviour this does NOT reproduce: registering a skip callback on only ONE
 * of the stock middleware (so trimming is skipped for a request but empty-to-null still runs, or
 * vice versa). Here a skip is all-or-nothing for the fused pass; `skipWhen()` skips the whole
 * clean. If you depend on per-middleware `skipWhen`, keep the stock pair. Configure excepts/skips
 * on THIS class (not on `TrimStrings`/`ConvertEmptyStringsToNull`) at the point you swap it in.
 */
class CleanRequestInput extends TransformsRequest
{
    /**
     * The attributes that should not be trimmed (the stock `TrimStrings` default).
     *
     * @var array<int, string>
     */
    protected $except = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * The globally registered attributes that should never be trimmed.
     *
     * @var array<int, string>
     */
    protected static $neverTrim = [];

    /**
     * The registered skip callbacks (skip the whole clean for a matching request).
     *
     * @var array<int, Closure>
     */
    protected static $skipCallbacks = [];

    /**
     * The trim-except set, compiled once per request from `$except` + `$neverTrim`.
     */
    private ?CompiledPatternSet $exceptSet = null;

    /**
     * Handle an incoming request.
     *
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        foreach (static::$skipCallbacks as $callback) {
            if ($callback($request)) {
                return $next($request);
            }
        }

        $this->exceptSet = new CompiledPatternSet(
            array_merge($this->except, static::$neverTrim)
        );

        $this->clean($request);

        return $next($request);
    }

    /**
     * Trim (unless the key is trim-excepted), then convert the empty string to null — the exact
     * composition of `TrimStrings` followed by `ConvertEmptyStringsToNull`.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @return mixed
     */
    protected function transform($key, $value)
    {
        if (is_string($value) && ! $this->exceptSet->matches($key)) {
            $value = Str::trim($value);
        }

        return $value === '' ? null : $value;
    }

    /**
     * Indicate that the given attributes should never be trimmed.
     *
     * @param  array<int, string>|string  $attributes
     * @return void
     */
    public static function except($attributes)
    {
        static::$neverTrim = array_values(array_unique(
            array_merge(static::$neverTrim, Arr::wrap($attributes))
        ));
    }

    /**
     * Register a callback that skips the entire clean for matching requests.
     *
     * @return void
     */
    public static function skipWhen(Closure $callback)
    {
        static::$skipCallbacks[] = $callback;
    }

    /**
     * Flush the middleware's global state (mirrors the framework middleware).
     *
     * @return void
     */
    public static function flushState()
    {
        static::$neverTrim = [];

        static::$skipCallbacks = [];
    }
}
