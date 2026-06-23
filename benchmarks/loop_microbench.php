<?php

/**
 * Micro-A/B for the @foreach $loop machinery (ManagesLoops). Per iteration vanilla
 * runs incrementLoopIndices() [array_merge of a 10-key array] + getLastLoop()
 * [(object) cast → a fresh stdClass every iteration]. Measures:
 *   (A) vanilla
 *   (B) direct-assign increment (kill the array_merge) + fresh object cast  <- safe
 *   (C) direct-assign + reuse one mutable stdClass                          <- UNSAFE upper bound
 * Run on Linux/JIT.
 */

// --- vanilla ManagesLoops (verbatim) ---
final class VanillaLoops
{
    public array $loopsStack = [];

    public function addLoop($data): void
    {
        $length = is_countable($data) ? count($data) : null;
        $parent = $this->loopsStack ? end($this->loopsStack) : null;
        $this->loopsStack[] = [
            'iteration' => 0, 'index' => 0, 'remaining' => $length ?? null, 'count' => $length,
            'first' => true, 'last' => isset($length) ? $length == 1 : null,
            'odd' => false, 'even' => true, 'depth' => count($this->loopsStack) + 1,
            'parent' => $parent ? (object) $parent : null,
        ];
    }

    public function incrementLoopIndices(): void
    {
        $loop = $this->loopsStack[$index = count($this->loopsStack) - 1];
        $this->loopsStack[$index] = array_merge($this->loopsStack[$index], [
            'iteration' => $loop['iteration'] + 1, 'index' => $loop['iteration'],
            'first' => $loop['iteration'] == 0, 'odd' => ! $loop['odd'], 'even' => ! $loop['even'],
            'remaining' => isset($loop['count']) ? $loop['remaining'] - 1 : null,
            'last' => isset($loop['count']) ? $loop['iteration'] == $loop['count'] - 1 : null,
        ]);
    }

    public function getLastLoop(): ?object
    {
        if ($last = ($this->loopsStack ? end($this->loopsStack) : null)) {
            return (object) $last;
        }
        return null;
    }

    public function popLoop(): void { array_pop($this->loopsStack); }
}

// --- (B) direct-assign increment, fresh object cast (byte-identical) ---
final class SafeLoops
{
    public array $loopsStack = [];

    public function addLoop($data): void
    {
        $length = is_countable($data) ? count($data) : null;
        $parent = $this->loopsStack ? end($this->loopsStack) : null;
        $this->loopsStack[] = [
            'iteration' => 0, 'index' => 0, 'remaining' => $length ?? null, 'count' => $length,
            'first' => true, 'last' => isset($length) ? $length == 1 : null,
            'odd' => false, 'even' => true, 'depth' => count($this->loopsStack) + 1,
            'parent' => $parent ? (object) $parent : null,
        ];
    }

    public function incrementLoopIndices(): void
    {
        $i = count($this->loopsStack) - 1;
        $loop = &$this->loopsStack[$i];
        $it = $loop['iteration'];
        $loop['iteration'] = $it + 1;
        $loop['index'] = $it;
        $loop['first'] = $it == 0;
        $loop['odd'] = ! $loop['odd'];
        $loop['even'] = ! $loop['even'];
        if (isset($loop['count'])) {
            $loop['remaining'] -= 1;
            $loop['last'] = $it == $loop['count'] - 1;
        }
    }

    public function getLastLoop(): ?object
    {
        if ($last = ($this->loopsStack ? end($this->loopsStack) : null)) {
            return (object) $last;
        }
        return null;
    }

    public function popLoop(): void { array_pop($this->loopsStack); }
}

// --- (C) reuse one mutable stdClass per level (UNSAFE: aliases across iterations) ---
final class ReuseLoops
{
    public array $loopsStack = [];
    private array $objs = [];

    public function addLoop($data): void
    {
        $length = is_countable($data) ? count($data) : null;
        $parent = $this->objs ? end($this->objs) : null;
        $o = new stdClass;
        $o->iteration = 0; $o->index = 0; $o->remaining = $length ?? null; $o->count = $length;
        $o->first = true; $o->last = isset($length) ? $length == 1 : null;
        $o->odd = false; $o->even = true; $o->depth = count($this->objs) + 1; $o->parent = $parent;
        $this->objs[] = $o;
    }

    public function incrementLoopIndices(): void
    {
        $o = end($this->objs);
        $it = $o->iteration;
        $o->iteration = $it + 1; $o->index = $it; $o->first = $it == 0;
        $o->odd = ! $o->odd; $o->even = ! $o->even;
        if ($o->count !== null) { $o->remaining -= 1; $o->last = $it == $o->count - 1; }
    }

    public function getLastLoop(): ?object { return $this->objs ? end($this->objs) : null; }
    public function popLoop(): void { array_pop($this->objs); }
}

// Parity: run one loop both ways, snapshot $loop each iteration, compare.
function run(object $env, array $data): array
{
    $snaps = [];
    $env->addLoop($data);
    foreach ($data as $_) {
        $env->incrementLoopIndices();
        $loop = $env->getLastLoop();
        $snaps[] = [$loop->index, $loop->iteration, $loop->first, $loop->last, $loop->remaining, $loop->odd, $loop->even];
    }
    $env->popLoop();
    return $snaps;
}

$data = range(1, 8);
$ref = run(new VanillaLoops, $data);
foreach (['SafeLoops' => new SafeLoops, 'ReuseLoops' => new ReuseLoops] as $name => $env) {
    if (run($env, $data) !== $ref) {
        fwrite(STDERR, "PARITY FAIL: $name\n");
        exit(1);
    }
}
echo "parity ✔ (Safe + Reuse per-iteration snapshots == vanilla)\n";

$ROWS = (int) ($argv[1] ?? 20);
$N = (int) ($argv[2] ?? 1_000_000);

$bench = function (string $label, string $class) use ($ROWS, $N) {
    $data = range(1, $ROWS);
    gc_collect_cycles();
    $t0 = hrtime(true);
    $sink = 0;
    for ($r = 0; $r < $N; $r++) {
        $env = new $class;
        $env->addLoop($data);
        foreach ($data as $_) {
            $env->incrementLoopIndices();
            $loop = $env->getLastLoop();
            $sink += $loop->index;
        }
        $env->popLoop();
    }
    $dt = (hrtime(true) - $t0) / 1e9;
    printf("  %-13s %6.3f s   (%.1f M iters/s)   sink=%d\n", $label, $dt, ($N * $ROWS) / $dt / 1e6, $sink);
    return $dt;
};

echo "@foreach \$loop machinery, {$N}× a {$ROWS}-row loop:\n";
$a = $bench('vanilla', VanillaLoops::class);
$b = $bench('safe', SafeLoops::class);
$c = $bench('reuse(unsafe)', ReuseLoops::class);
printf("\nsafe vs vanilla:          %+.1f%%\n", ($b - $a) / $a * 100);
printf("reuse vs vanilla:         %+.1f%%   (upper bound, not byte-safe)\n", ($c - $a) / $a * 100);
