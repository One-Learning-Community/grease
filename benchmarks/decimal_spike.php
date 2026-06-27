<?php

/**
 * SPIKE — decimal cast identity short-circuit (pre-build proof, throwaway until wired).
 *
 * The `decimal:N` cast runs, per cast per row, `asDecimal($v, $N)` =
 *   (string) BigDecimal::of((string) $v)->toScale($N, RoundingMode::HalfUp)   // Brick\Math
 * The stack_excimer profile surfaced this as ~5–7% inclusive of a decimal-heavy serialize
 * (Brick\Math\BigNumber::of / ::_of) — a genuine per-row multiplier, currently unaccelerated.
 *
 * The fast path: when the stored value is already a string in *exactly* the canonical scaled
 * form (e.g. "10.50" for N=2), `asDecimal` is a no-op round-trip, so return the string as-is
 * and skip Brick entirely — the same identity-probe shape as HasGreasedSerialization's date
 * short-circuit.
 *
 * WHY IT'S SAFE FOR MONEY (the whole point): the fast path NEVER ROUNDS and never reformats.
 * It fires only on a value already at the exact target scale with no leading-zero / sign /
 * notation quirks, where toScale() is provably a no-op. ANYTHING that would need rounding or
 * reformatting ("10.5", "10.567", "010.50", "-0.00", "1e2", non-strings, garbage) fails the
 * regex and defers to Brick. The risk is ASYMMETRIC and we exploit that:
 *
 *     fires(v) ⟹ fastResult(v) === Brick(v)        ← the only invariant that matters
 *     over-deferring is free (falls to Brick, just slower) — mis-firing is the catastrophe
 *
 * This proves that invariant ONE-DIRECTIONALLY by fuzzing a large adversarial corpus through
 * the fast path AND the REAL framework asDecimal (Brick) oracle, asserting that whenever the
 * fast path fires it equals Brick, and that it never fires where Brick throws. Then it times
 * the win on a realistic money corpus. Exit code is non-zero on ANY violation.
 *
 *   php -d xdebug.mode=off -d memory_limit=1G benchmarks/decimal_spike.php [fuzz_per_scale] [timing_iters]
 */

require __DIR__.'/../vendor/autoload.php';

use Illuminate\Database\Eloquent\Model;

// --- The oracle: the REAL framework asDecimal (Brick), via a public proxy. Version-proof —
//     whatever brick/math + RoundingMode the installed framework uses is exactly what runs. ---
$oracleModel = new class extends Model
{
    public function pub($v, int $n): string
    {
        return $this->asDecimal($v, $n);
    }
};
$oracle = function ($v, int $n) use ($oracleModel): array {
    try {
        return [true, $oracleModel->pub($v, $n)]; // [ok, result]
    } catch (\Throwable $e) {
        return [false, null]; // Brick threw (vanilla would throw too)
    }
};

// --- The fast path. Returns [fired, result]. Conservative by construction. ---
$fast = function ($value, int $decimals): array {
    if (! is_string($value)) {
        return [false, null];
    }
    $pattern = $decimals === 0
        ? '/^-?(?:0|[1-9][0-9]*)$/'
        : '/^-?(?:0|[1-9][0-9]*)\.[0-9]{'.$decimals.'}$/';
    if (! preg_match($pattern, $value)) {
        return [false, null];
    }
    // Negative zero ("-0", "-0.00") — Brick normalizes the sign away, so it would NOT
    // round-trip. A leading '-' with no non-zero digit anywhere ⇒ defer.
    if ($value[0] === '-' && strpbrk($value, '123456789') === false) {
        return [false, null];
    }

    return [true, $value];
};

$fuzzPer = (int) ($argv[1] ?? 40000);
$timingIters = (int) ($argv[2] ?? 200);

// ====================================================================================
//  PARITY FUZZ — the assurance.
// ====================================================================================

mt_srand(20260627); // deterministic / reproducible

$scales = [0, 1, 2, 3, 4];
$violations = [];
$fires = 0;
$defers = 0;
$oracleThrows = 0;
$firedWhereOracleThrew = 0;
$canonicalChecked = 0;
$canonicalFired = 0;
$total = 0;

$check = function ($value, int $n) use ($fast, $oracle, &$violations, &$fires, &$defers, &$oracleThrows, &$firedWhereOracleThrew, &$total): void {
    $total++;
    [$fired, $fres] = $fast($value, $n);
    [$ok, $ores] = $oracle($value, $n);

    if ($fired) {
        $fires++;
        if (! $ok) {
            $firedWhereOracleThrew++;
            $violations[] = ['type' => 'FIRED_BUT_ORACLE_THREW', 'value' => var_export($value, true), 'n' => $n];
        } elseif ($fres !== $ores) {
            $violations[] = ['type' => 'FIRE_MISMATCH', 'value' => var_export($value, true), 'n' => $n, 'fast' => $fres, 'oracle' => $ores];
        }
    } else {
        $defers++;
    }
    if (! $ok) {
        // counted once here for reporting (independent of fire)
    }
};

// Track oracle-throw count separately (clean).
$oracleThrowProbe = function ($value, int $n) use ($oracle, &$oracleThrows): void {
    [$ok] = $oracle($value, $n);
    if (! $ok) {
        $oracleThrows++;
    }
};

foreach ($scales as $n) {
    // (1) Random CANONICAL values — must FIRE and must match (coverage + correctness).
    for ($i = 0; $i < $fuzzPer; $i++) {
        $neg = mt_rand(0, 3) === 0 ? '-' : '';
        // int part: 0, or a no-leading-zero integer of random length (occasionally huge).
        if (mt_rand(0, 4) === 0) {
            $int = '0';
        } else {
            $len = mt_rand(0, 1) ? mt_rand(1, 6) : mt_rand(1, 25); // sometimes very large
            $int = (string) mt_rand(1, 9);
            for ($d = 1; $d < $len; $d++) {
                $int .= (string) mt_rand(0, 9);
            }
        }
        $frac = '';
        for ($d = 0; $d < $n; $d++) {
            $frac .= (string) mt_rand(0, 9);
        }
        $value = $n === 0 ? $neg.$int : $neg.$int.'.'.$frac;
        // skip the negative-zero we deliberately exclude (it's tested in the adversarial set)
        if ($neg === '-' && strpbrk($value, '123456789') === false) {
            continue;
        }

        $canonicalChecked++;
        [$fired] = $fast($value, $n);
        if ($fired) {
            $canonicalFired++;
        } else {
            $violations[] = ['type' => 'CANONICAL_DID_NOT_FIRE', 'value' => var_export($value, true), 'n' => $n];
        }
        $check($value, $n);
        $oracleThrowProbe($value, $n);
    }

    // (2) Random PERTURBATIONS — non-canonical shapes. Mostly defer; any that fire must match.
    for ($i = 0; $i < intdiv($fuzzPer, 2); $i++) {
        $base = (string) mt_rand(0, 99999);
        $variants = [
            '0'.$base.'.'.str_repeat('5', max(1, $n)),      // leading zero
            $base.'.'.str_repeat('5', $n + 1),               // one too many frac digits
            $base.'.'.str_repeat('5', max(0, $n - 1)),       // one too few
            $base,                                            // no decimal point
            ' '.$base.'.'.str_repeat('0', $n).' ',           // surrounding spaces
            '+'.$base.'.'.str_repeat('0', $n),               // plus sign
            $base.'.'.str_repeat('0', $n).'e2',              // scientific
            $base.'..'.str_repeat('0', $n),                  // double dot
            '-0'.($n ? '.'.str_repeat('0', $n) : ''),        // negative zero
        ];
        foreach ($variants as $v) {
            $check($v, $n);
            $oracleThrowProbe($v, $n);
        }
    }

    // (3) Non-string and garbage — must always defer.
    foreach ([10, -5, 10.5, 0.1, true, false, null, '', '.', '-', 'abc', '1.2.3', '1,234.56', '1e3', 'NaN', 'INF', '0x1A'] as $v) {
        [$fired] = $fast($v, $n);
        if ($fired) {
            $violations[] = ['type' => 'GARBAGE_FIRED', 'value' => var_export($v, true), 'n' => $n];
        }
        $check($v, $n);
    }
}

// (4) Hand-picked adversarial cases at N=2 (money), each with the expected behaviour.
$adversarial = [
    '10.50', '0.00', '0.05', '-10.50', '-0.01', '100.00', '999999999999999999.99',
    '10.5', '10', '010.50', '00.50', '-0.00', '-0', '+10.50', '10.000', '10.', '.50',
    ' 10.50', '10.50 ', '1e2', '1.0E+2', '', '-', 'abc', '10,50', '0.000', '1.234',
];
foreach ($adversarial as $v) {
    $check($v, 2);
    $oracleThrowProbe($v, 2);
}

// ====================================================================================
//  REPORT
// ====================================================================================

echo "decimal identity short-circuit — PARITY FUZZ vs real framework asDecimal (Brick)\n";
echo str_repeat('=', 78)."\n";
printf("cases:            %d   (scales %s + adversarial)\n", $total, implode(',', $scales));
printf("fired:            %d  (%.1f%%)\n", $fires, $fires / max(1, $total) * 100);
printf("deferred:         %d  (%.1f%%)\n", $defers, $defers / max(1, $total) * 100);
printf("oracle throws:    %d  (fast deferred all of these)\n", $oracleThrows);
printf("canonical fired:  %d / %d  (coverage of the common case)\n", $canonicalFired, $canonicalChecked);
echo str_repeat('-', 78)."\n";

if ($violations) {
    printf("VIOLATIONS: %d  ❌  (a fire that disagreed with Brick — DO NOT SHIP)\n", count($violations));
    foreach (array_slice($violations, 0, 20) as $v) {
        echo '  '.json_encode($v)."\n";
    }
    echo "\nSPIKE FAILED — the fast path is not byte-identical. Tighten the guard.\n";
    exit(1);
}

echo "VIOLATIONS: 0  ✅  every fire byte-identical to Brick; never fired where Brick threw.\n";
echo "  → asymmetric-risk invariant holds: fires(v) ⟹ fastResult(v) === Brick(v).\n";

// ====================================================================================
//  TIMING A/B — realistic money corpus (canonical "d.dd" strings, the DB-hydrated case).
// ====================================================================================

$corpus = [];
for ($i = 0; $i < 1000; $i++) {
    $corpus[] = number_format(mt_rand(0, 99999) + mt_rand(0, 99) / 100, 2, '.', '');
}

// Confirm 100% fire on the realistic corpus (what a money column actually yields).
$fireN = 0;
foreach ($corpus as $v) {
    if ($fast($v, 2)[0]) {
        $fireN++;
    }
}

// Warm.
foreach ($corpus as $v) {
    $oracleModel->pub($v, 2);
    $fast($v, 2);
}

$timeIt = function (callable $fn) use ($timingIters, $corpus): float {
    $t0 = hrtime(true);
    for ($it = 0; $it < $timingIters; $it++) {
        foreach ($corpus as $v) {
            $fn($v);
        }
    }

    return (hrtime(true) - $t0) / ($timingIters * count($corpus)); // ns/op
};

$nsVanilla = $timeIt(fn ($v) => $oracleModel->pub($v, 2));
$nsFast = $timeIt(function ($v) use ($fast, $oracleModel) {
    [$fired, $res] = $fast($v, 2);

    return $fired ? $res : $oracleModel->pub($v, 2);
});

echo "\nTIMING — 1000 canonical money values × $timingIters iters (macOS — ratios directional)\n";
echo str_repeat('-', 78)."\n";
printf("realistic fire rate: %d/1000 (%.0f%%)\n", $fireN, $fireN / 10);
printf("vanilla (Brick):     %7.1f ns/op\n", $nsVanilla);
printf("fast path:           %7.1f ns/op\n", $nsFast);
printf("delta:               %+.1f%%\n", ($nsFast - $nsVanilla) / $nsVanilla * 100);
