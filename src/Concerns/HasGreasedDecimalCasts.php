<?php

namespace Grease\Concerns;

use Grease\GreasedModel;

/**
 * Standalone, opt-in fast path for `decimal:N` casts — the read twin of the date identity
 * short-circuit in {@see HasGreasedSerialization}, kept DELIBERATELY OUT of the
 * {@see HasGrease} umbrella (and {@see GreasedModel}) so that money-bearing models
 * adopt it — and can audit and roll it back — in isolation. Add it explicitly:
 *
 *   class Invoice extends Model
 *   {
 *       use HasGreasedDecimalCasts;            // alone, on a plain model
 *   }
 *
 *   class Order extends Model
 *   {
 *       use HasGrease, HasGreasedDecimalCasts; // or alongside the full bundle
 *   }
 *
 * Vanilla casts a `decimal:N` value with
 *   asDecimal($v, $N) = (string) BigDecimal::of((string) $v)->toScale($N, RoundingMode::HalfUp)
 * — a Brick\Math arbitrary-precision parse + rescale, paid per cast per row on every read. The
 * `stack_excimer` profile put it at ~5-7% inclusive of a decimal-heavy serialize.
 *
 * When the stored value is ALREADY a string in the exact canonical scaled form (e.g. "10.50"
 * for N=2), `toScale()` changes nothing and the result is the input verbatim — so this returns
 * it as-is and skips Brick entirely.
 *
 * WHY THIS IS SAFE FOR MONEY — it NEVER ROUNDS and never reformats. The guard fires only on a
 * value already at the exact target scale, with no leading zero, no sign-on-zero, and no
 * non-decimal notation — precisely the inputs where `toScale()` is provably a no-op. Anything
 * that would need rounding or reformatting ("10.5", "10.567", "010.50", "-0.00", "1e2",
 * non-strings, garbage) fails the guard and defers to Brick UNTOUCHED, exactly as vanilla does
 * today. The risk is asymmetric and that is the whole point: over-deferring only forfeits the
 * speedup; the fast path can never emit a value that differs from Brick. That invariant is
 * proven byte-for-byte against the real framework `asDecimal` oracle over 1M+ fuzzed cases
 * (scales 0-4 + adversarial money strings) in tests/HasGreasedDecimalCastsParityTest.php.
 *
 * STATELESS by construction — the guard reads only the passed `$value` and `$decimals`, never
 * cached cast metadata — so, unlike every other tier, there is NO blueprint entry, NO divergence
 * flag, and NO invalidation to get wrong: a runtime `mergeCasts()`/`withCasts()` simply changes
 * the `$decimals` vanilla hands in, and the guard re-evaluates per value. It needs nothing else
 * from Grease; on a `HasGrease` model the decimal flyweight ({@see HasGreasedCasts}) already
 * routes through `asDecimal()`, so the override is picked up either way.
 *
 * Custom `Castable`/`CastsAttributes` decimals never reach `asDecimal()` (they dispatch through
 * their own caster), so they are untouched.
 */
trait HasGreasedDecimalCasts
{
    /**
     * {@inheritDoc}
     */
    protected function asDecimal($value, $decimals)
    {
        // Only a non-negative integer scale is fast-pathable; anything else (a malformed
        // `decimal:` spec) defers so vanilla owns whatever it does with it. ctype_digit also
        // keeps a non-numeric scale out of the interpolated pattern.
        if (is_string($value) && ctype_digit((string) $decimals)) {
            $pattern = (int) $decimals === 0
                ? '/^-?(?:0|[1-9][0-9]*)$/'
                : '/^-?(?:0|[1-9][0-9]*)\.[0-9]{'.((int) $decimals).'}$/';

            // Canonical scaled string ⇒ toScale() is a no-op ⇒ return verbatim. The second
            // clause rejects negative zero ("-0", "-0.00"), which Brick normalises to "0…",
            // so it would NOT round-trip and must defer.
            if (preg_match($pattern, $value) === 1
                && ($value[0] !== '-' || strpbrk($value, '123456789') !== false)) {
                return $value;
            }
        }

        return parent::asDecimal($value, $decimals);
    }
}
