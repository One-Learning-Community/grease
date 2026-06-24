<?php

/**
 * Grease eager view-resolution index — A/B + parity gate.
 *
 * Vanilla `FileViewFinder::find()` resolves a view name to its file by stat-walking
 * `paths × extensions` (`file_exists` syscalls); the per-process `$views` memo only covers
 * in-process repeats and is rebuilt every FPM process (and a MISS is never memoized). The eager
 * index (`grease:view-cache`) pre-seeds the resolution so a known name is an array hit with zero
 * stats, from request one.
 *
 * The honest, platform-independent metric is the STAT COUNT eliminated; wall-time is secondary
 * (and macOS-noisy). This models an FPM request: a fresh finder (cold) resolving the views a page
 * touches. Vanilla pays one stat per first-touch (more for misses); the seeded finder pays zero.
 *
 *   php benchmarks/view_cache_ab.php [requests]
 */

require __DIR__.'/../vendor/autoload.php';

use Grease\View\FileViewFinder as GreasedFinder;
use Illuminate\Filesystem\Filesystem;
use Illuminate\View\FileViewFinder as VanillaFinder;

/** A filesystem that counts exists() calls — the stat-walk we're eliminating. */
class CountingFilesystem extends Filesystem
{
    public int $existsCalls = 0;

    public function exists($path): bool
    {
        $this->existsCalls++;

        return parent::exists($path);
    }
}

$dir = sys_get_temp_dir().'/grease_vcab_'.getmypid();
@mkdir($dir.'/components', 0777, true);
@mkdir($dir.'/layouts', 0777, true);
$names = [];
for ($i = 0; $i < 10; $i++) {
    file_put_contents("$dir/components/c$i.blade.php", "c$i");
    file_put_contents("$dir/layouts/l$i.blade.php", "l$i");
    $names[] = "components.c$i";
    $names[] = "layouts.l$i";
}

// Build the index as grease:view-cache would (name => resolved source path).
$builder = new VanillaFinder(new Filesystem, [$dir]);
$index = [];
foreach ($names as $n) {
    $index[$n] = $builder->find($n);
}

// ---- Parity gate ----------------------------------------------------------------

$fs = new CountingFilesystem;
$vanilla = new VanillaFinder($fs, [$dir]);
$greased = GreasedFinder::fromBase(new VanillaFinder($fs, [$dir]));
$greased->useGreaseViewIndex($index);

foreach ($names as $n) {
    if ($vanilla->find($n) !== $greased->find($n)) {
        echo "PARITY FAILED — find('$n') diverged.\n";
        exit(1);
    }
}
// A name NOT in the index must still resolve live, identically.
file_put_contents("$dir/loose.blade.php", 'loose');
if ($vanilla->find('loose') !== $greased->find('loose')) {
    echo "PARITY FAILED — non-indexed fallback diverged.\n";
    exit(1);
}
echo 'Parity: OK ('.count($names)." views resolve identically + non-indexed fallback)\n";

// ---- Stat-count (the honest metric) — one cold FPM request --------------------

$vfs = new CountingFilesystem;
$vfinder = new VanillaFinder($vfs, [$dir]);
foreach ($names as $n) {
    $vfinder->find($n);
}

$gfs = new CountingFilesystem;
$gfinder = GreasedFinder::fromBase(new VanillaFinder($gfs, [$dir]));
$gfinder->useGreaseViewIndex($index);
foreach ($names as $n) {
    $gfinder->find($n);
}

printf("\nCold resolution of %d views (one FPM request):\n", count($names));
printf("  vanilla file_exists() calls: %d\n", $vfs->existsCalls);
printf("  greased file_exists() calls: %d  (index hits → no stats)\n", $gfs->existsCalls);

// ---- Wall-time (secondary, macOS-noisy) ---------------------------------------

$requests = (int) ($argv[1] ?? 100_000);

function timeArm(callable $makeFinder, array $names, int $n): float
{
    $start = hrtime(true);
    for ($i = 0; $i < $n; $i++) {
        $finder = $makeFinder();   // fresh finder per "request" (FPM)
        foreach ($names as $name) {
            $finder->find($name);
        }
    }

    return (hrtime(true) - $start) / 1e9;
}

$plainFs = new Filesystem;
$van = fn () => new VanillaFinder($plainFs, [$dir]);
$gre = function () use ($plainFs, $dir, $index) {
    $f = GreasedFinder::fromBase(new VanillaFinder($plainFs, [$dir]));
    $f->useGreaseViewIndex($index);

    return $f;
};

// warm
timeArm($van, $names, 2000);
timeArm($gre, $names, 2000);

$rounds = 5;
$v = $g = 0.0;
for ($r = 0; $r < $rounds; $r++) {
    $v += timeArm($van, $names, $requests);
    $g += timeArm($gre, $names, $requests);
}
$v /= $rounds;
$g /= $rounds;

printf("\nFresh-finder-per-request resolve of %d views, %s requests × %d rounds:\n", count($names), number_format($requests), $rounds);
printf("  vanilla: %.4f s  (%.3f µs/request)\n", $v, $v / $requests * 1e6);
printf("  greased: %.4f s  (%.3f µs/request)\n", $g, $g / $requests * 1e6);
printf("  delta:   %+.1f%%\n", ($g - $v) / $v * 100);

// cleanup
array_map('unlink', glob("$dir/components/*") ?: []);
array_map('unlink', glob("$dir/layouts/*") ?: []);
@unlink("$dir/loose.blade.php");
@rmdir("$dir/components");
@rmdir("$dir/layouts");
@rmdir($dir);

echo "\nStat count is the honest, platform-independent metric (syscalls eliminated); the never-memoized\n";
echo "MISS path (dynamic/@include(\$var) names) is the permanent win even under Octane. macOS wall-time noisy.\n";
