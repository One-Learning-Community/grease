<?php

/**
 * Grease validation parse-memo — A/B + parity gate.
 *
 * `ValidationRuleParser::parse('max:255')` → `['Max', ['255']]` is a pure function of the rule
 * string, but it's recomputed every time: `Validator::getRule()` loops EVERY rule of an attribute
 * and parses each, and it's called from many probes (hasRule/isValidatable/requireParameterCount…)
 * per validation. The same exploded string is re-parsed repeatedly within one `passes()`.
 *
 * The tier memoizes parse at the one clean seam — overriding the short `getRule()` (validateAttribute
 * is long; duplicating it is fragile, and its parse is once-per-rule, not the looped multiplier). It
 * measures a realistic FormRequest-shaped validation END TO END (fresh validator per "request", as a
 * real request builds), not just the isolated parse loop — the honest question is how much of a real
 * validation this moves, since the rule methods themselves do work.
 *
 *   php benchmarks/validation_ab.php [iterations]
 *
 * Uses the shipped Grease\Validation\Validator (override of getRule with a static parse memo).
 */

require __DIR__.'/../vendor/autoload.php';

use Grease\Validation\Validator as GreasedValidator;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Validator;

function translator(): Translator
{
    return new Translator(new ArrayLoader, 'en');
}

// A realistic 6-field FormRequest rule set.
$rules = [
    'name' => 'required|string|max:255',
    'email' => 'required|email|max:255',
    'age' => 'required|integer|min:18|max:120',
    'bio' => 'nullable|string|max:1000',
    'role' => 'required|in:admin,user,guest',
    'active' => 'required|boolean',
];
$data = [
    'name' => 'Alice', 'email' => 'alice@example.com', 'age' => 30,
    'bio' => 'hello there', 'role' => 'admin', 'active' => true,
];
$badData = ['name' => '', 'email' => 'nope', 'age' => 5, 'role' => 'x', 'active' => 'maybe'];

// ---- Parity gate ----------------------------------------------------------------

foreach (['valid' => $data, 'invalid' => $badData] as $label => $input) {
    $van = new Validator(translator(), $input, $rules);
    $gre = new GreasedValidator(translator(), $input, $rules);
    if ($van->passes() !== $gre->passes()) {
        echo "PARITY FAILED — passes() differs for $label\n";
        exit(1);
    }
    if (var_export($van->errors()->toArray(), true) !== var_export($gre->errors()->toArray(), true)) {
        echo "PARITY FAILED — errors differ for $label\n";
        echo 'vanilla: '.var_export($van->errors()->toArray(), true)."\n";
        echo 'greased: '.var_export($gre->errors()->toArray(), true)."\n";
        exit(1);
    }
}
echo "Parity: OK (passes() + error bags identical, valid & invalid)\n";

// ---- Benchmark: fresh validator per request, full passes() ---------------------

$iterations = (int) ($argv[1] ?? 50_000);

function timeArm(string $class, Translator $t, array $data, array $rules, int $n): float
{
    $start = hrtime(true);
    for ($i = 0; $i < $n; $i++) {
        $v = new $class($t, $data, $rules);
        $v->passes();
    }

    return (hrtime(true) - $start) / 1e9;
}

$t = translator();
for ($i = 0; $i < 1000; $i++) {
    timeArm(Validator::class, $t, $data, $rules, 1);
    timeArm(GreasedValidator::class, $t, $data, $rules, 1);
}

$rounds = 5;
$v = $g = 0.0;
for ($r = 0; $r < $rounds; $r++) {
    if ($r % 2 === 0) {
        $v += timeArm(Validator::class, $t, $data, $rules, $iterations);
        $g += timeArm(GreasedValidator::class, $t, $data, $rules, $iterations);
    } else {
        $g += timeArm(GreasedValidator::class, $t, $data, $rules, $iterations);
        $v += timeArm(Validator::class, $t, $data, $rules, $iterations);
    }
}
$v /= $rounds;
$g /= $rounds;

printf("\nFresh validator + passes() on a 6-field rule set, %s iters × %d rounds:\n", number_format($iterations), $rounds);
printf("  vanilla: %.4f s  (%.3f µs/validation)\n", $v, $v / $iterations * 1e6);
printf("  greased: %.4f s  (%.3f µs/validation)\n", $g, $g / $iterations * 1e6);
printf("  delta:   %+.1f%%\n", ($g - $v) / $v * 100);
echo "\nEnd-to-end on a real validation (the rule methods do work too), so this is the honest delta —\n";
echo "smaller than the isolated parse loop. Validation runs only on validating endpoints. macOS.\n";
