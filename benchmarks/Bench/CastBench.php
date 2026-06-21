<?php

namespace Grease\Bench;

use Grease\Tests\Fixtures\GreasedSample;
use Grease\Tests\Fixtures\SampleData;
use Grease\Tests\Fixtures\VanillaSample;
use Illuminate\Database\Capsule\Manager as Capsule;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\RetryThreshold;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Warmup;

/**
 * In-memory A/B micro-benchmark: vanilla vs greased, on the exact fixtures the
 * test suite proves byte-identical. Compare the paired `*Vanilla` / `*Greased`
 * subjects to read the per-operation delta. A real (in-memory) connection is
 * booted once so date casts resolve their format exactly as in an app.
 */
#[
    BeforeMethods('setUp'),
    Warmup(2),
    Revs(1000),
    Iterations(10),
    RetryThreshold(3),
]
class CastBench
{
    private static bool $booted = false;

    private VanillaSample $vanilla;

    private GreasedSample $greased;

    public function setUp(): void
    {
        if (! self::$booted) {
            $capsule = new Capsule;
            $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
            $capsule->setAsGlobal();
            $capsule->bootEloquent();
            self::$booted = true;
        }

        $this->vanilla = (new VanillaSample)->newFromBuilder(SampleData::row());
        $this->greased = (new GreasedSample)->newFromBuilder(SampleData::row());
    }

    public function benchReadVanilla(): void
    {
        $this->readAll($this->vanilla);
    }

    public function benchReadGreased(): void
    {
        $this->readAll($this->greased);
    }

    public function benchToArrayVanilla(): void
    {
        $this->vanilla->attributesToArray();
    }

    public function benchToArrayGreased(): void
    {
        $this->greased->attributesToArray();
    }

    public function benchHydrateVanilla(): void
    {
        (new VanillaSample)->newFromBuilder(SampleData::row());
    }

    public function benchHydrateGreased(): void
    {
        (new GreasedSample)->newFromBuilder(SampleData::row());
    }

    public function benchSetDirtyVanilla(): void
    {
        $this->setAndDirty($this->vanilla);
    }

    public function benchSetDirtyGreased(): void
    {
        $this->setAndDirty($this->greased);
    }

    private function readAll($m): void
    {
        $m->int_val;
        $m->real_val;
        $m->float_val;
        $m->dec_val;
        $m->str_val;
        $m->bool_val;
        $m->obj_val;
        $m->arr_val;
        $m->json_val;
        $m->coll_val;
        $m->date_val;
        $m->dt_val;
        $m->cdt_val;
        $m->imm_date_val;
        $m->imm_dt_val;
        $m->icdt_val;
        $m->ts_val;
        $m->status_val;
        $m->upper_val;
    }

    private function setAndDirty($m): void
    {
        $m->int_val = 999;
        $m->bool_val = false;
        $m->arr_val = ['changed' => true];
        $m->dt_val = '2026-06-06 06:06:06';

        $m->getDirty();
    }
}
