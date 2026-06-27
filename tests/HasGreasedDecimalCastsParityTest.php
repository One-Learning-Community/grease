<?php

namespace Grease\Tests;

use Grease\Concerns\HasGrease;
use Grease\Concerns\HasGreasedDecimalCasts;
use Illuminate\Database\Eloquent\Model;

/**
 * {@see HasGreasedDecimalCasts::asDecimal()} returns an already-canonical scaled string verbatim
 * (skipping the Brick\Math round-trip) and defers everything else to vanilla. The contract is
 * byte-for-byte identical to vanilla `asDecimal()` for EVERY input — proven here against the real
 * framework method as the oracle, both directly and through full `toArray()`/`getAttribute()`.
 *
 * The decisive test is the fuzz: the only failure mode that matters is the fast path FIRING with
 * a value that differs from Brick (money corruption). Over-deferring is harmless. So a large
 * adversarial corpus asserts `greased->asDecimal === vanilla->asDecimal` for all of it — a
 * mismatch can only come from a wrong fire. Vanilla is the oracle.
 */
class HasGreasedDecimalCastsParityTest extends TestCase
{
    /** A canonical money string must be returned verbatim by BOTH (agreement + identity). */
    public function test_canonical_values_are_byte_identical_passthrough(): void
    {
        $vanilla = new VanillaDC;
        $greased = new GreasedDC;

        foreach (['10.50', '0.00', '0.05', '-10.50', '-0.01', '100.00', '999999999999999999.99'] as $v) {
            $this->assertSame($v, $vanilla->pubAsDecimal($v, 2), "vanilla identity for $v");
            $this->assertSame($vanilla->pubAsDecimal($v, 2), $greased->pubAsDecimal($v, 2), "parity for $v");
        }
    }

    /** Non-canonical / dirty / garbage inputs must defer — greased must still equal vanilla. */
    public function test_non_canonical_inputs_match_vanilla(): void
    {
        $vanilla = new VanillaDC;
        $greased = new GreasedDC;

        // Each of these would be wrong to pass through verbatim; vanilla reformats or throws,
        // and greased must reproduce vanilla exactly (by deferring).
        $cases = ['10.5', '10', '010.50', '00.50', '-0.00', '-0', '+10.50', '10.000', '10.', '.50', ' 10.50', '10.50 ', '1e2', '1.0E+2', '10,50', '0.000', '1.234'];

        foreach ($cases as $v) {
            $this->assertSame(
                $this->oracle($vanilla, $v, 2),
                $this->oracle($greased, $v, 2),
                'parity for '.var_export($v, true),
            );
        }
    }

    /** Non-string values never fast-path (asDecimal stringifies them); must match vanilla. */
    public function test_non_string_values_match_vanilla(): void
    {
        $vanilla = new VanillaDC;
        $greased = new GreasedDC;

        foreach ([10, -5, 1050, 0] as $v) {
            $this->assertSame($vanilla->pubAsDecimal($v, 2), $greased->pubAsDecimal($v, 2), "parity for int $v");
        }
    }

    /**
     * The assurance: 150k+ adversarial cases across scales 0-4, asserting greased === vanilla
     * for all of them. A mismatch can only be a wrong fire (over-deferral keeps them equal).
     */
    public function test_fuzz_against_brick_oracle(): void
    {
        $vanilla = new VanillaDC;
        $greased = new GreasedDC;

        mt_srand(20260627);
        $checked = 0;
        $canonicalFired = 0;

        foreach ([0, 1, 2, 3, 4] as $n) {
            for ($i = 0; $i < 30000; $i++) {
                $value = $this->randomString($n);

                $v = $this->oracle($vanilla, $value, $n);
                $g = $this->oracle($greased, $value, $n);
                $this->assertSame($v, $g, 'fuzz mismatch: asDecimal('.var_export($value, true).", $n)");
                $checked++;

                // Coverage: a freshly-built canonical value must come back verbatim (i.e. the
                // fast path is genuinely exercised, not silently dead).
                if ($this->isCanonical($value, $n) && $g === $value) {
                    $canonicalFired++;
                }
            }
        }

        $this->assertGreaterThan(0, $canonicalFired, 'fast path never fired — test proves nothing');
        $this->assertGreaterThan(100000, $checked);
    }

    /** decimal:0 (integer scale) — canonical integers pass through; non-integers defer. */
    public function test_decimal_zero_scale(): void
    {
        $vanilla = new VanillaDC;
        $greased = new GreasedDC;

        foreach (['10', '0', '-10', '999', '10.0', '10.5', '-0', '010', '', 'x'] as $v) {
            $this->assertSame($this->oracle($vanilla, $v, 0), $this->oracle($greased, $v, 0), 'scale-0 parity for '.var_export($v, true));
        }
    }

    /** A malformed scale must defer (never inject into the pattern); match vanilla's behaviour. */
    public function test_malformed_scale_defers(): void
    {
        $vanilla = new VanillaDC;
        $greased = new GreasedDC;

        foreach (['abc', '', '-1', '2.0'] as $decimals) {
            $this->assertSame(
                $this->oracle($vanilla, '10.50', $decimals),
                $this->oracle($greased, '10.50', $decimals),
                'malformed scale '.var_export($decimals, true),
            );
        }
    }

    /** In place: full toArray() over a hydrated row is byte-identical, canonical and dirty. */
    public function test_full_toarray_matches_vanilla(): void
    {
        foreach (['10.50', '10.5', '0.00', '-0.00', '1234.00'] as $stored) {
            $row = ['id' => 1, 'price' => $stored, 'tax' => $stored, 'qty' => $stored];

            $vanilla = (new VanillaDC)->newFromBuilder($row);
            $greased = (new GreasedDC)->newFromBuilder($row);

            $this->assertSame(
                json_encode($vanilla->toArray()),
                json_encode($greased->toArray()),
                "toArray parity for stored $stored",
            );
            $this->assertSame($vanilla->price, $greased->price, "getAttribute parity for $stored");
        }
    }

    /** Standalone trait (no HasGrease) and the full bundle must both equal vanilla. */
    public function test_standalone_and_composed_both_match_vanilla(): void
    {
        $row = ['id' => 1, 'price' => '19.99', 'tax' => '1.60', 'qty' => '3.00'];

        $vanilla = (new VanillaDC)->newFromBuilder($row)->toArray();
        $standalone = (new GreasedDC)->newFromBuilder($row)->toArray();      // HasGreasedDecimalCasts only
        $composed = (new GreasedDCFull)->newFromBuilder($row)->toArray();    // HasGrease + the trait

        $this->assertSame(json_encode($vanilla), json_encode($standalone), 'standalone trait diverged');
        $this->assertSame(json_encode($vanilla), json_encode($composed), 'composed with HasGrease diverged');
    }

    // --- helpers ---------------------------------------------------------------------------

    /** Call asDecimal, normalising a thrown Brick exception to a sentinel so both arms compare. */
    private function oracle(Model $m, $value, $decimals): string
    {
        try {
            return $m->pubAsDecimal($value, $decimals);
        } catch (\Throwable $e) {
            return '__THREW__:'.get_class($e);
        }
    }

    private function isCanonical($value, int $n): bool
    {
        $pattern = $n === 0 ? '/^-?(?:0|[1-9][0-9]*)$/' : '/^-?(?:0|[1-9][0-9]*)\.[0-9]{'.$n.'}$/';

        return is_string($value) && preg_match($pattern, $value) === 1
            && ($value[0] !== '-' || strpbrk($value, '123456789') !== false);
    }

    /** A random numeric-ish string: mix of canonical and adversarial perturbations. */
    private function randomString(int $n)
    {
        switch (mt_rand(0, 6)) {
            case 0: case 1: case 2: // canonical
                $neg = mt_rand(0, 3) === 0 ? '-' : '';
                $int = mt_rand(0, 4) === 0 ? '0' : (string) mt_rand(1, 9).substr((string) mt_rand(0, 999999), 0, mt_rand(0, 6));
                $frac = '';
                for ($d = 0; $d < $n; $d++) {
                    $frac .= (string) mt_rand(0, 9);
                }

                return $n === 0 ? $neg.$int : $neg.$int.'.'.$frac;
            case 3: // wrong frac count
                return mt_rand(0, 9999).'.'.str_repeat('5', $n + mt_rand(1, 2));
            case 4: // leading zero / no dot / spaces
                return [' '.mt_rand(0, 99).'.00', '0'.mt_rand(1, 99).'.50', (string) mt_rand(0, 999)][mt_rand(0, 2)];
            case 5: // negative-zero / notation / garbage
                return ['-0'.($n ? '.'.str_repeat('0', $n) : ''), '1e3', 'abc', '', '1.2.3'][mt_rand(0, 4)];
            default: // non-string
                return [42, -7, 3.14][mt_rand(0, 2)];
        }
    }
}

class VanillaDC extends Model
{
    protected $table = 'dc';

    protected $casts = ['price' => 'decimal:2', 'tax' => 'decimal:2', 'qty' => 'decimal:2'];

    public function pubAsDecimal($v, $n): string
    {
        return $this->asDecimal($v, $n);
    }
}

class GreasedDC extends Model
{
    use HasGreasedDecimalCasts;

    protected $table = 'dc';

    protected $casts = ['price' => 'decimal:2', 'tax' => 'decimal:2', 'qty' => 'decimal:2'];

    public function pubAsDecimal($v, $n): string
    {
        return $this->asDecimal($v, $n);
    }
}

class GreasedDCFull extends Model
{
    use HasGrease;
    use HasGreasedDecimalCasts;

    protected $table = 'dc';

    protected $casts = ['price' => 'decimal:2', 'tax' => 'decimal:2', 'qty' => 'decimal:2'];
}
