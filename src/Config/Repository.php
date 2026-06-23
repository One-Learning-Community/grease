<?php

namespace Grease\Config;

use Closure;
use Illuminate\Config\Repository as BaseRepository;
use Illuminate\Support\Arr;
use stdClass;

/**
 * Grease config tier — per-key memoization of resolved config values.
 *
 * Vanilla {@see BaseRepository::get()} funnels every `config('a.b.c')` read through
 * `Arr::get($this->items, $key, $default)`, which re-runs `explode('.', $key)` and walks
 * the nested array on every call. Config is immutable-in-practice and the same handful of
 * keys (`app.env`, `app.debug`, `app.timezone`, `database.default`, `cache.default`, …) are
 * read many times per request from all over the framework — a true per-call multiplier. The
 * resolved value is a pure function of `$items[$key]` until a write, so it memoizes cleanly.
 *
 * Discipline mirrors the other Grease memos:
 *   - **Per-instance**, keyed by the full key string (no dot-walk on a hit). The `config`
 *     repository is a single long-lived singleton, so under a persistent worker (Octane) the
 *     memo survives across requests — warm reads become pure hash hits forever.
 *   - **`array_key_exists`, not `??=`** — the null-memo trap. A config value of `null` is a
 *     legitimate stored value; `??=` would re-walk it every read.
 *   - **Only keys that EXIST are memoized.** A missing key's result depends on the per-call
 *     `$default`, so it must never be cached. A private sentinel passed as `Arr::get`'s
 *     default distinguishes a *stored* `null` (found → memoize) from a *missing* key (→
 *     return `value($default)`, never memoize) in a single walk.
 *   - **Any write flushes the whole memo.** `Arr::set` can shadow parent keys, so per-key
 *     invalidation is unsafe; every write funnels through {@see set()}, so one override covers
 *     `set`/`prepend`/`push`/`offsetSet`/`offsetUnset`.
 *
 * `getMany()`/`has()` keep the vanilla path (correct, just unaccelerated) — `get()` is the
 * hot multiplier.
 *
 * **Under a persistent worker (Octane).** Octane sandboxes config *per request* — its
 * `CreateConfigurationSandbox` listener runs `instance('config', clone $sandbox['config'])` on
 * every `RequestReceived`, so each request handles against a fresh *clone* of the base
 * repository and any runtime `config([...])` mutation is isolated to that sandbox and
 * discarded (Octane handles cross-request leakage — it is not a footgun). That clone is
 * parity-safe with this memo: PHP's shallow clone copies `$items` and `$greaseConfigMemo`
 * together, and they are consistent at clone time, so each request's memo matches its own
 * `$items` exactly as a cloned vanilla repository would (no `__clone` needed — the same
 * shallow-clone semantics vanilla already relies on; proven by the clone-sandbox parity test).
 * Consequence: the memo amortizes *within* a request (config is read many times per request)
 * under FPM and Octane alike, but does **not** persist across requests (Octane re-clones each
 * one) — so there is no cross-request cache to protect, and equally no end-of-request flush is
 * needed. Within a request the memo stays consistent with `$items` at every instant because
 * `set()` flushes and rewrites together. The single carve-out is **out-of-band mutation** of
 * `$items` — a macro or reflection writing the protected array directly, bypassing `set()` —
 * the exact analogue of the Request tier's "direct bag mutation" caveat;
 * {@see flushConfigMemo()} is the explicit hook for that case.
 *
 * Arrays and nested reads are safe by construction: `get('app')` returns the whole sub-array
 * and `get('app.name')` a scalar leaf, each memoized under its own key, and both come back by
 * PHP's copy-on-write value semantics — a caller mutating a returned array separates its own
 * copy and never touches the memo (identical to vanilla returning out of `$items`). A write to
 * any child flushes the whole memo (not per-key — `Arr::set` can shadow parents), so a stale
 * parent/child can never be served.
 */
class Repository extends BaseRepository
{
    /**
     * Resolved values for keys that exist in `$items`, keyed by full key string.
     *
     * @var array<string, mixed>
     */
    protected array $greaseConfigMemo = [];

    /**
     * Build a greased repository carrying over an existing repository's items — the swap
     * seam used by {@see GreaseConfigServiceProvider} (the events `fromBase()` precedent).
     */
    public static function fromBase(BaseRepository $base): static
    {
        return new static($base->all());
    }

    /**
     * {@inheritDoc}
     */
    public function get($key, $default = null)
    {
        if (is_array($key)) {
            return $this->getMany($key);
        }

        // Only the string-key `config('a.b.c')` hot path is memoized; null/other key types
        // are rare and defer to vanilla untouched.
        if (! is_string($key)) {
            return Arr::get($this->items, $key, $default);
        }

        if (array_key_exists($key, $this->greaseConfigMemo)) {
            return $this->greaseConfigMemo[$key];
        }

        // A sentinel as the Arr::get default tells a stored `null` (found) apart from a
        // genuinely-missing key in one walk — without a second `Arr::has` pass.
        static $missing;
        $missing ??= new stdClass;

        $value = Arr::get($this->items, $key, $missing);

        if ($value === $missing) {
            // Absent — the result is the per-call default, so it must not be memoized.
            // Byte-identical to vanilla's `value($default)` (no extra args).
            return $default instanceof Closure ? $default() : $default;
        }

        return $this->greaseConfigMemo[$key] = $value;
    }

    /**
     * {@inheritDoc}
     */
    public function set($key, $value = null)
    {
        // Arr::set can shadow parent keys, so any write invalidates the whole memo
        // (per-key invalidation is unsafe). prepend()/push()/offsetSet()/offsetUnset() all
        // funnel through here, so this single override covers every write path.
        $this->flushConfigMemo();

        parent::set($key, $value);
    }

    /**
     * Drop the entire read memo. `set()` already calls this, so for set()-routed mutations
     * the memo stays consistent with `$items` automatically. Call it explicitly only after
     * an out-of-band `$items` mutation (a macro or reflection write — the documented
     * carve-out), or to force a clean memo. Cheap when the memo is already empty.
     */
    public function flushConfigMemo(): void
    {
        $this->greaseConfigMemo = [];
    }
}
