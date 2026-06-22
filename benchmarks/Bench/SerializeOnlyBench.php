<?php

namespace Grease\Bench;

use Grease\Bench\Support\BootsEloquent;
use Grease\Tests\Fixtures\GreasedSample;
use Grease\Tests\Fixtures\SampleData;
use Grease\Tests\Fixtures\VanillaSample;
use Illuminate\Support\Arr;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\RetryThreshold;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Warmup;
use RuntimeException;

/**
 * Curated-subset serialization: pick a few columns from a wide, cast-heavy model —
 * the Scout `toSearchableArray` / `JsonResource` / export shape. The realistic
 * "before" is `Arr::only($model->attributesToArray(), $keys)`: serialize all 23
 * columns (every cast, date, enum, decimal) and then throw most away.
 * `greaseSerializeOnly()` narrows visibility first, so only the requested keys are
 * ever serialized — and, unlike `setVisible(...)->attributesToArray()`, leaves the
 * model's visible list untouched.
 *
 * Each subject hydrates a FRESH model per rev (a request serializes each row once),
 * so the cast cache never pre-warms and the measurement is the per-row cost a real
 * export pays. Pinned to UTC — the zone the date tier is certified under.
 */
#[
    BeforeMethods('setUp'),
    Warmup(2),
    Revs(1000),
    Iterations(10),
    RetryThreshold(3),
]
class SerializeOnlyBench
{
    /** Three of twenty-three columns — a plain string, an enum cast, and a timestamp. */
    private const KEYS = ['str_val', 'status_val', 'created_at'];

    public function setUp(): void
    {
        date_default_timezone_set('UTC');

        BootsEloquent::capsule();

        // Fidelity guard: the primitive must equal the naive filter it replaces, or
        // the bench is timing two different results. Parity is proven exhaustively in
        // SerializeOnlyParityTest; this refuses to report a misleading delta.
        $g = (new GreasedSample)->newFromBuilder(SampleData::row());
        $naive = Arr::only((new VanillaSample)->newFromBuilder(SampleData::row())->attributesToArray(), self::KEYS);

        if (json_encode($g->greaseSerializeOnly(self::KEYS)) !== json_encode($naive)) {
            throw new RuntimeException('SerializeOnly bench parity broken — output is not byte-identical.');
        }
    }

    public function benchOnlyNaiveVanilla(): void
    {
        $m = (new VanillaSample)->newFromBuilder(SampleData::row());

        $out = Arr::only($m->attributesToArray(), self::KEYS);
    }

    public function benchOnlyNaiveGreased(): void
    {
        $m = (new GreasedSample)->newFromBuilder(SampleData::row());

        $out = Arr::only($m->attributesToArray(), self::KEYS);
    }

    public function benchOnlySetVisibleGreased(): void
    {
        $m = (new GreasedSample)->newFromBuilder(SampleData::row());

        $out = $m->setVisible(self::KEYS)->attributesToArray();
    }

    public function benchOnlyPrimitiveGreased(): void
    {
        $m = (new GreasedSample)->newFromBuilder(SampleData::row());

        $out = $m->greaseSerializeOnly(self::KEYS);
    }
}
