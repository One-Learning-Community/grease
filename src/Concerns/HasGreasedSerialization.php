<?php

namespace Grease\Concerns;

use Closure;

/**
 * Tier 4 — date serialization round-trip elimination.
 *
 * Eloquent serializes every `getDates()` column (the built-in `created_at` /
 * `updated_at` timestamps) by parsing the stored driver string into a Carbon
 * instance and formatting it straight back out:
 *
 *     $attributes[$key] = $this->serializeDate($this->asDateTime($value));
 *
 * For the overwhelmingly common configurations that Carbon trip is pure
 * ceremony — the output is a deterministic rewrite of the input string:
 *
 *   - **identity** — a model whose `serializeDate()` emits the storage format
 *     (`format('Y-m-d H:i:s')`, a frequent API choice): output === stored string.
 *   - **utc_iso** — the *default* `serializeDate()` (`toJSON()`) under a zero-offset
 *     timezone (Laravel's own default is UTC): `2026-01-01 00:00:00` becomes
 *     `2026-01-01T00:00:00.000000Z`, i.e. `strtr($v,' ','T').'.000000Z'` — no
 *     timezone math, no Carbon needed.
 *
 * THE NARROWING (why this is safe, asserted in tests): we never reimplement
 * Carbon's formatting blind. The per-class strategy is chosen by *probing the
 * model's real `serializeDate(asDateTime(...))`* against representative values and
 * adopting a fast path **only when it is byte-for-byte equal** to vanilla. Anything
 * the probe doesn't certify — a non-UTC zone under the default formatter, a custom
 * `dateFormat`, a value that isn't a plain second-precision string (Carbon instance,
 * date-only, sub-second precision) — defers to the exact vanilla composition. The
 * plan is keyed by timezone + connection so it can never go stale when either
 * changes.
 *
 * The same treatment covers `datetime` / `immutable_datetime` *casts* (the
 * `published_at => 'datetime'` shape) in `addCastAttributesToArray`: certified keys
 * are rewritten in place and handed to `parent::` on the skip-list, so every other
 * cast — custom-format datetime, `date`, enum, class-castable, the lot — flows
 * through the framework's own logic untouched. Each cast type is probe-certified
 * independently; uncertified ones defer.
 */
trait HasGreasedSerialization
{
    use InteractsWithGreaseBlueprint;

    /** A plain second-precision storage timestamp — the only shape the fast path handles. */
    private const GREASE_DATE_SHAPE = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/';

    /** Representative values used to certify a class's serialize strategy. */
    private const GREASE_DATE_PROBES = [
        '2026-01-01 00:00:00',
        '2026-03-04 09:10:11',
        '2024-12-31 23:59:59',
        '1999-02-28 07:08:09',
        '2020-06-15 13:45:30',
    ];

    protected function addDateAttributesToArray(array $attributes)
    {
        $plan = $this->greaseDateSerializePlan();

        if ($plan === false) {
            // Class not certified for a fast path (e.g. non-UTC default formatter,
            // custom dateFormat) — exactly vanilla.
            return parent::addDateAttributesToArray($attributes);
        }

        foreach ($this->getDates() as $key) {
            if ($key === null || ! isset($attributes[$key])) {
                continue;
            }

            $value = $attributes[$key];

            // Fast path only for a raw, second-precision storage string. A Carbon
            // instance, a date-only value, or sub-second precision falls through to
            // the byte-for-byte vanilla composition below.
            if (is_string($value) && preg_match(self::GREASE_DATE_SHAPE, $value) === 1) {
                $attributes[$key] = $plan === 'utc_iso'
                    ? strtr($value, ' ', 'T').'.000000Z'
                    : $value; // 'identity'

                continue;
            }

            $attributes[$key] = $this->serializeDate($this->asDateTime($value));
        }

        return $attributes;
    }

    protected function addCastAttributesToArray(array $attributes, array $mutatedAttributes)
    {
        // Live casts (so a runtime-diverged instance is always correct, never the
        // class baseline). Only the two plain datetime cast types are eligible —
        // custom-format datetime, `date`, `timestamp`, enum, class-castable, etc.
        // all fall to vanilla below.
        $fast = [];

        foreach ($this->getCasts() as $key => $type) {
            if (($type !== 'datetime' && $type !== 'immutable_datetime')
                || ! array_key_exists($key, $attributes)
                || in_array($key, $mutatedAttributes, true)) {
                continue;
            }

            $value = $attributes[$key];

            if (! is_string($value) || preg_match(self::GREASE_DATE_SHAPE, $value) !== 1) {
                continue;
            }

            $rewrite = $this->greaseDateCastRewrite($type);

            if ($rewrite === false) {
                continue;
            }

            $attributes[$key] = $rewrite($value);
            $fast[] = $key;
        }

        // Hand the rewritten keys to vanilla on the skip-list, so every other cast
        // is processed by the framework's own logic, byte-for-byte.
        return parent::addCastAttributesToArray(
            $attributes,
            $fast === [] ? $mutatedAttributes : array_merge($mutatedAttributes, $fast),
        );
    }

    /**
     * Serialize one stored datetime the way `attributesToArray()` would — without
     * routing the whole model through it. This is the array-builder's date fast
     * path exposed as a primitive, so code that *hand-picks* attributes (Scout
     * `toSearchableArray`, a `JsonResource`, an export) can capture the date-tier
     * win it would otherwise leave on the table by reading the attribute directly.
     *
     * The return is **byte-identical** to the composition Eloquent applies to a
     * timestamp / plain `datetime` cast on its way into the array:
     *
     *     $this->serializeDate($this->asDateTime($this->attributes[$key]))
     *
     * which for the default serializer is the `toJSON()` form
     * (`2026-01-01T00:00:00.000000Z`). When the per-class probe certifies a fast
     * path (UTC-default ISO, or a storage-format `serializeDate`) the Carbon
     * round-trip is skipped; otherwise — an uncertified class, or a value that
     * isn't a plain second-precision storage string — it falls back to the exact
     * vanilla composition, so it is always correct, only sometimes faster.
     *
     * FORMAT NOTE: this is the `toJSON` shape, NOT `toIso8601String()` (`+00:00`,
     * no microseconds) or `toDateTimeString()` (storage form) — it is a drop-in
     * only for a field already emitting the array/JSON serialization. Eligible for
     * the fast path are exactly the attributes the array builder fast-paths:
     * timestamps and plain `datetime` / `immutable_datetime` casts. A `date` cast,
     * a custom-format datetime, or a custom `CastsAttributes::serialize` is not
     * something this primitive can reproduce — read those through the model.
     */
    public function greaseSerializeDate(string $key): ?string
    {
        $raw = $this->attributes[$key] ?? null;

        if ($raw === null) {
            return null;
        }

        // Fast path only for a raw, second-precision storage string under a
        // certified class plan — mirroring addDateAttributesToArray exactly.
        if (is_string($raw) && preg_match(self::GREASE_DATE_SHAPE, $raw) === 1) {
            $plan = $this->greaseDateSerializePlan();

            if ($plan !== false) {
                return $plan === 'utc_iso'
                    ? strtr($raw, ' ', 'T').'.000000Z'
                    : $raw; // 'identity'
            }
        }

        // Anything uncertified, or a Carbon/date-only/sub-second value: defer to
        // the byte-for-byte vanilla composition.
        return $this->serializeDate($this->asDateTime($raw));
    }

    /**
     * Serialize a curated subset of the model to its array form — the same output as
     * `Arr::only($this->attributesToArray(), $keys)`, but without serializing the
     * keys that filter would immediately throw away. The whole greased array path
     * (date tier included) runs over the narrowed set, so a hand-picked subset that
     * names `created_at` still gets the date-tier win, and a wide model picked down
     * to a few columns skips the cast/mutator/append work for everything else.
     *
     * Non-mutating: the model's own `visible` list is restored before returning, so
     * this is the speed of `setVisible(...)->attributesToArray()` without the
     * permanent visibility change (and without a `clone`). The model's existing
     * `visible`/`hidden` configuration is still honored — a requested key the model
     * hides does not reappear — which is exactly why the result stays byte-identical
     * to filtering the full `attributesToArray()` after the fact.
     *
     * @param  array<int, string>  $keys
     * @return array<string, mixed>
     */
    public function greaseSerializeOnly(array $keys): array
    {
        $original = $this->getVisible();

        // Intersect the request with any visibility the model already enforces, so
        // the narrowed serialization can never expose a key the full attributesToArray()
        // would have withheld. (An empty visible list means "no restriction", so the
        // request stands alone.) No surviving keys → nothing to serialize.
        $visible = $original === [] ? $keys : array_values(array_intersect($original, $keys));

        if ($visible === []) {
            return [];
        }

        $this->setVisible($visible);

        try {
            return $this->attributesToArray();
        } finally {
            $this->setVisible($original);
        }
    }

    /**
     * The certified serialize strategy for this class under the live timezone and
     * connection: 'identity', 'utc_iso', or false (defer to vanilla). Keyed by
     * timezone + connection because the default-formatter rewrite is only valid at
     * a zero offset and the storage format is connection-scoped.
     *
     * @return 'identity'|'utc_iso'|false
     */
    protected function greaseDateSerializePlan(): string|false
    {
        $tz = date_default_timezone_get();
        $conn = $this->getConnectionName() ?? '@default';

        return static::$greaseBlueprint[static::class]['dateSerialize'][$tz][$conn]
            ??= $this->greaseBuildDateSerializePlan();
    }

    /**
     * Certify a fast path for this class by probing its real serialize composition.
     * Returns a strategy only when every probe round-trips byte-identically.
     *
     * @return 'identity'|'utc_iso'|false
     */
    protected function greaseBuildDateSerializePlan(): string|false
    {
        // Only the standard second-precision driver format is eligible; a custom
        // format may carry precision/width the probe shape doesn't represent.
        if ($this->getDateFormat() !== 'Y-m-d H:i:s') {
            return false;
        }

        $identity = true;
        $utcIso = true;

        foreach (self::GREASE_DATE_PROBES as $probe) {
            $real = $this->serializeDate($this->asDateTime($probe));

            $identity = $identity && $real === $probe;
            $utcIso = $utcIso && $real === strtr($probe, ' ', 'T').'.000000Z';
        }

        return $identity ? 'identity' : ($utcIso ? 'utc_iso' : false);
    }

    /**
     * The certified raw-string rewrite for a `datetime`/`immutable_datetime` cast
     * under the live timezone + connection, or false to defer to vanilla. Cached
     * per cast type, since the immutable variant could in principle serialize
     * differently from the mutable one.
     */
    protected function greaseDateCastRewrite(string $type): Closure|false
    {
        $tz = date_default_timezone_get();
        $conn = $this->getConnectionName() ?? '@default';

        return static::$greaseBlueprint[static::class]['dateCast'][$tz][$conn][$type]
            ??= $this->greaseBuildDateCastRewrite($type);
    }

    /**
     * Certify a Carbon-free rewrite for a datetime cast type by probing its real
     * cast-then-serialize composition. Returns the first candidate rewrite that
     * reproduces it on every probe, or false when none does (custom serializer
     * under a non-zero offset, a custom `dateFormat`, etc.).
     */
    protected function greaseBuildDateCastRewrite(string $type): Closure|false
    {
        if ($this->getDateFormat() !== 'Y-m-d H:i:s') {
            return false;
        }

        // Mirrors what addCastAttributesToArray feeds to serializeDate for this cast
        // type (castAttribute's date branch + the serializeDate post-step).
        $real = match ($type) {
            'datetime' => fn (string $raw): string => $this->serializeDate($this->asDateTime($raw)),
            'immutable_datetime' => fn (string $raw): string => $this->serializeDate($this->asDateTime($raw)->toImmutable()),
            default => null,
        };

        if ($real === null) {
            return false;
        }

        // Same two strategies as the timestamp path: UTC-default ISO, or a
        // storage-format serializeDate (identity).
        $candidates = [
            static fn (string $raw): string => strtr($raw, ' ', 'T').'.000000Z',
            static fn (string $raw): string => $raw,
        ];

        foreach ($candidates as $candidate) {
            foreach (self::GREASE_DATE_PROBES as $probe) {
                if ($real($probe) !== $candidate($probe)) {
                    continue 2;
                }
            }

            return $candidate;
        }

        return false;
    }
}
