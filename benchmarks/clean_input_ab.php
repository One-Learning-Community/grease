<?php

/**
 * A/B for a PROPOSED byte-identical drop-in: fusing TrimStrings + ConvertEmptyStringsToNull
 * into ONE pass, AND replacing the per-leaf Str::is except-check with a compiled hybrid matcher
 * (literals → isset() hash, wildcards → one merged regex — see except_match_ab.php).
 *
 * Laravel's default global stack runs both middleware; each extends TransformsRequest, whose
 * clean() recursively walks the WHOLE input tree and rebuilds the bag. So vanilla:
 *   - traverses the full input twice (trim, then null-empty) + rebuilds twice, and
 *   - TrimStrings::transform re-runs array_merge($except,$neverTrim) + Str::is() on EVERY leaf.
 *
 * Three arms (vanilla arm uses the REAL framework middleware — no optimistic replication):
 *   vanilla       TrimStrings->handle + ConvertEmptyStringsToNull->handle (two passes)
 *   fused         one pass, except-merge hoisted, but still Str::is per leaf
 *   fused+hybrid  one pass + the compiled hybrid except matcher (Str::is gone)
 *
 * Output is identical (" " --trim--> "" --null), parity-gated before any timing. Three payloads:
 * a realistic ~10-leaf request, a wide ~100-leaf one, and a DEEP ProseMirror-style content post
 * (the case that actually motivates this — vanilla's per-node dotted-prefix concat grows with
 * nesting depth, which the wide payload never exercises).
 *
 *   php -d xdebug.mode=off -d opcache.jit=tracing -d opcache.enable_cli=1 -d memory_limit=1G \
 *       benchmarks/clean_input_ab.php [iters]
 */

require __DIR__.'/../vendor/autoload.php';

use Grease\Http\Middleware\CleanRequestInput;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\TransformsRequest;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

$iters = (int) ($argv[1] ?? 8000);

// Fused, but still Str::is per leaf (the except-merge is hoisted out of the leaf loop).
class FusedCleanInput extends TransformsRequest
{
    protected $except = ['current_password', 'password', 'password_confirmation'];

    private array $exceptMerged = [];

    public function handle($request, Closure $next)
    {
        $this->exceptMerged = $this->except; // (+ static neverTrim in a real impl)
        $this->clean($request);

        return $next($request);
    }

    protected function transform($key, $value)
    {
        if (is_string($value) && ! Str::is($this->exceptMerged, $key)) {
            $value = Str::trim($value);
        }

        return $value === '' ? null : $value;
    }
}

// The "fused + hybrid" arm is the SHIPPED class — Grease\Http\Middleware\CleanRequestInput
// (one pass + a compiled CompiledPatternSet except matcher). So this bench times exactly what
// CleanRequestInputParityTest proves byte-identical.

// --- Payloads. A realistic ~10-leaf request and an input-heavy ~100-leaf one. ---
function smallPayload(): array
{
    return [
        'name' => '  Jane Doe  ',
        'email' => 'jane@example.test',
        'phone' => '',
        'company' => '',
        'age' => 34,
        'newsletter' => true,
        'address' => ['city' => ' Townsville ', 'zip' => '00012'],
        'password' => '  secret  ',   // except → must NOT be trimmed
        'note' => '  hi  ',
    ];
}
function largePayload(): array
{
    $p = [];
    for ($i = 0; $i < 30; $i++) {
        $p["field_$i"] = $i % 4 === 0 ? '  spaced value  ' : ($i % 4 === 1 ? '' : ($i % 4 === 2 ? "clean$i" : $i));
    }
    $p['addresses'] = [];
    for ($a = 0; $a < 5; $a++) {
        $p['addresses'][] = [
            'line1' => "  $a Main St  ", 'line2' => '', 'city' => 'Townsville',
            'state' => ' ST ', 'zip' => "0000$a", 'country' => '  US  ',
        ];
    }
    $p['items'] = [];
    for ($n = 0; $n < 10; $n++) {
        $p['items'][] = ['sku' => "  SKU-$n  ", 'name' => '', 'qty' => $n, 'note' => '  note  '];
    }

    return $p;
}

/**
 * A realistic *deep* content post: a ProseMirror/TipTap/Lexical-style rich-text document, the
 * shape a blog post / CMS body / comment editor actually submits. Unlike largePayload (wide and
 * shallow, depth ~2), this is genuinely NESTED — doc → block → (nested list →)* → text node →
 * mark → attrs — so it exercises the part of vanilla's cost that grows with DEPTH: every node
 * rebuilds the dotted `$keyPrefix.$key` from an ever-longer accumulated string. Deterministic.
 */
function deepDocumentPayload(): array
{
    // A text node carrying marks (links/styles) with their own attr bags — the deep leaves.
    $text = function (string $t, bool $linked = false) {
        $node = ['type' => 'text', 'text' => $t];
        if ($linked) {
            $node['marks'] = [[
                'type' => 'link',
                'attrs' => ['href' => '  https://example.test/x  ', 'title' => '', 'target' => '  _blank  '],
            ]];
        }

        return $node;
    };

    // A paragraph block: a few text runs, some whitespace-padded, some empty (→ null), some linked.
    $paragraph = function (int $i) use ($text) {
        return [
            'type' => 'paragraph',
            'attrs' => ['textAlign' => $i % 3 === 0 ? '  left  ' : '', 'indent' => $i % 4],
            'content' => [
                $text("  Sentence $i with a fair bit of text to walk.  "),
                $text(' ', $i % 2 === 0),
                $text('', true),
                $text("clean-run-$i"),
            ],
        ];
    };

    // A bullet list whose items each hold a paragraph AND a nested sub-list — the depth driver.
    $nestedList = function (int $section) use ($paragraph) {
        $items = [];
        for ($li = 0; $li < 3; $li++) {
            $items[] = [
                'type' => 'listItem',
                'attrs' => ['checked' => $li % 2 === 0],
                'content' => [
                    $paragraph($section * 10 + $li),
                    [
                        'type' => 'bulletList',
                        'content' => [[
                            'type' => 'listItem',
                            'content' => [$paragraph($section * 100 + $li)],
                        ]],
                    ],
                ],
            ];
        }

        return ['type' => 'bulletList', 'content' => $items];
    };

    $content = [];
    for ($s = 0; $s < 8; $s++) {
        $content[] = ['type' => 'heading', 'attrs' => ['level' => ($s % 3) + 1], 'content' => [
            // headings carry a single text run; some padded, some empty
            ['type' => 'text', 'text' => $s % 2 === 0 ? "  Heading $s  " : ''],
        ]];
        $content[] = $paragraph($s);
        $content[] = $nestedList($s);
    }

    return [
        'title' => '  My Post Title  ',
        'slug' => '',
        'status' => 'draft',
        'body' => ['type' => 'doc', 'attrs' => ['version' => 1], 'content' => $content],
    ];
}

$trim = new TrimStrings;
$convert = new ConvertEmptyStringsToNull;
$fused = new FusedCleanInput;
$fast = new CleanRequestInput;
$noop = fn ($r) => null;

$runVanilla = fn (Request $r) => $trim->handle($r, fn ($r2) => $convert->handle($r2, $noop));
$runFused = fn (Request $r) => $fused->handle($r, $noop);
$runFast = fn (Request $r) => $fast->handle($r, $noop);

$best = function (callable $f) use ($iters): float {
    $m = PHP_FLOAT_MAX;
    for ($r = 0; $r < 7; $r++) {
        $t = hrtime(true);
        for ($i = 0; $i < $iters; $i++) {
            $f();
        }
        $m = min($m, (hrtime(true) - $t) / $iters);
    }

    return $m;
};

$us = fn (float $ns) => sprintf('%.2fµs', $ns / 1000);
$pct = fn (float $a, float $b) => sprintf('%+.1f%%', $a > 0 ? ($b - $a) / $a * 100 : 0);

echo 'jit: '.((opcache_get_status(false)['jit']['on'] ?? false) ? 'YES' : 'no')."   iters=$iters\n";

$payloads = [
    'small' => smallPayload(),
    'large (wide)' => largePayload(),
    'deep document' => deepDocumentPayload(),
];

// Max nesting depth of an array tree — the dimension largePayload doesn't exercise.
$maxDepth = function (array $a) use (&$maxDepth): int {
    $d = 1;
    foreach ($a as $v) {
        if (is_array($v)) {
            $d = max($d, 1 + $maxDepth($v));
        }
    }

    return $d;
};

foreach ($payloads as $label => $payload) {
    $leaves = 0;
    array_walk_recursive($payload, function () use (&$leaves) {
        $leaves++;
    });
    $depth = $maxDepth($payload);

    $mk = function () use ($payload): Request {
        $r = Request::create('/x', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload));
        $r->json(); // prime the memoized InputBag

        return $r;
    };

    // Parity: all three arms produce byte-identical cleaned input.
    $out = [];
    foreach (['vanilla' => $runVanilla, 'fused' => $runFused, 'fast' => $runFast] as $name => $run) {
        $r = $mk();
        $run($r);
        $out[$name] = json_encode($r->json()->all());
    }
    if ($out['vanilla'] !== $out['fused'] || $out['vanilla'] !== $out['fast']) {
        fwrite(STDERR, "PARITY FAIL [$label]\n  van:  {$out['vanilla']}\n  fused:{$out['fused']}\n  fast: {$out['fast']}\n");
        exit(1);
    }

    // warm
    for ($i = 0; $i < 500; $i++) {
        $runVanilla($mk());
        $runFused($mk());
        $runFast($mk());
    }

    $baseline = $best(fn () => $mk());
    $vT = $best(fn () => $runVanilla($mk()));
    $fT = $best(fn () => $runFused($mk()));
    $hT = $best(fn () => $runFast($mk()));

    $vClean = max(0, $vT - $baseline);
    $fClean = max(0, $fT - $baseline);
    $hClean = max(0, $hT - $baseline);

    echo "\n== $label payload: $leaves leaves, depth $depth — clean cost (request-build baseline subtracted) ==\n";
    printf("  %-22s %s\n", 'vanilla (trim+convert)', $us($vClean));
    printf("  %-22s %s   (%s vs vanilla)\n", 'fused (Str::is)', $us($fClean), $pct($vClean, $fClean));
    printf("  %-22s %s   (%s vs vanilla, %s vs fused)\n", 'fused + hybrid', $us($hClean), $pct($vClean, $hClean), $pct($fClean, $hClean));
}

echo "\nParity: OK — fused & fused+hybrid byte-identical to vanilla two-pass on all payloads.\n";
