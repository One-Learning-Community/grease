<?php

/**
 * Host-side merge step for the live benchmark JSON. Takes the macro doc that
 * realworld.php already wrote, folds in the Blade variants (from blade.php --json) and
 * the per-operation / events deltas (parsed straight out of a phpbench result dump), and
 * rewrites the single docs/.vitepress/data/benchmarks.json the docs render from.
 *
 * Keeping phpbench as the source for the micro numbers means there's exactly ONE
 * authoritative micro-benchmark (the same `composer bench` runs), not a parallel
 * re-implementation that could drift. We just read its results.
 *
 *   php benchmarks/augment-metrics.php <benchmarks.json> <blade.json> <phpbench-dump.xml>
 */

[$self, $mainPath, $bladePath, $xmlPath] = array_pad($argv, 4, null);

if (! $mainPath || ! is_file($mainPath)) {
    fwrite(STDERR, "usage: php augment-metrics.php <benchmarks.json> <blade.json> <phpbench-dump.xml>\n");
    exit(1);
}

$doc = json_decode(file_get_contents($mainPath), true);
if (! is_array($doc) || empty($doc['macro'])) {
    fwrite(STDERR, "$mainPath is not a macro payload\n");
    exit(1);
}

// --- Blade variants ---------------------------------------------------------------
if ($bladePath && is_file($bladePath)) {
    $blade = json_decode(file_get_contents($bladePath), true);
    if (! empty($blade['blade'])) {
        $doc['blade'] = $blade['blade'];
    }
}

// --- Per-operation + events, parsed from the phpbench dump ------------------------
// Each phpbench subject is `bench{Op}{Vanilla|Greased}`; pair them by {Op} and read the
// `<stats mean>` (microseconds). The map decides which subjects surface and how.
$MAP = [
    'Hydrate' => ['perOp', 'hydrate', 'hydrate a row', 10],
    'Read' => ['perOp', 'read', 'read all casts', 20],
    'EnumRead' => ['perOp', 'enum', 'read an enum cast', 30],
    'SetDirty' => ['perOp', 'setDirty', 'set + dirty-check', 40],
    'ToArray' => ['perOp', 'toArray', 'toArray() (serialize)', 50],
    'SerializeDates' => ['perOp', 'dateTimestamps', 'date serialization (timestamps)', 60],
    'SerializeDatetimeCasts' => ['perOp', 'dateCasts', 'date serialization (datetime casts)', 70],
    'NoListener' => ['events', 'noListener', 'dispatch, no listener', 10],
    'WithListeners' => ['events', 'withListeners', 'dispatch, with listeners', 20],
    'Lean' => ['events', 'eventDenseWarm', 'event-dense request, warm', 30],
    'Cold' => ['events', 'eventDenseCold', 'event-dense request, cold (wildcards)', 40],
];

if ($xmlPath && is_file($xmlPath)) {
    $xml = simplexml_load_file($xmlPath);

    // Collect mean (µs) per {base op, arm}.
    $means = [];
    foreach ($xml->xpath('//subject') as $subject) {
        $name = (string) $subject['name'];
        if (! preg_match('/^bench(.*?)(Vanilla|Greased)$/', $name, $m)) {
            continue;
        }
        [$base, $arm] = [$m[1], strtolower($m[2])];

        $stats = $subject->xpath('.//stats');
        if (! $stats) {
            continue;
        }
        $means[$base][$arm] = (float) $stats[0]['mean'];
    }

    $sections = ['perOp' => [], 'events' => []];
    foreach ($MAP as $base => [$section, $key, $label, $order]) {
        if (! isset($means[$base]['vanilla'], $means[$base]['greased'])) {
            continue;
        }
        $v = $means[$base]['vanilla'];
        $g = $means[$base]['greased'];
        $sections[$section][] = [
            'key' => $key,
            'label' => $label,
            'order' => $order,
            'vanilla_us' => round($v, 2),
            'grease_us' => round($g, 2),
            'delta_pct' => round(($g - $v) / $v * 100, 1),
        ];
    }

    foreach ($sections as $name => $rows) {
        usort($rows, fn ($a, $b) => $a['order'] <=> $b['order']);
        $rows = array_map(function ($r) {
            unset($r['order']);

            return $r;
        }, $rows);
        if ($rows) {
            $doc[$name] = $rows;
        }
    }
}

$json = json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
file_put_contents($mainPath, $json);

$counts = array_map(fn ($k) => count($doc[$k] ?? []), ['macro', 'blade', 'perOp', 'events']);
fwrite(STDERR, sprintf(
    "merged → %s (macro %d, blade %d, perOp %d, events %d)\n",
    $mainPath, ...$counts,
));
