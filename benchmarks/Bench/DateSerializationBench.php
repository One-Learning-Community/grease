<?php

namespace Grease\Bench;

use Grease\Tests\Fixtures\GreasedDatetimeCast;
use Grease\Tests\Fixtures\GreasedTimestamps;
use Grease\Tests\Fixtures\SampleData;
use Grease\Tests\Fixtures\VanillaDatetimeCast;
use Grease\Tests\Fixtures\VanillaTimestamps;
use Illuminate\Database\Capsule\Manager as Capsule;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\RetryThreshold;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Warmup;
use RuntimeException;

/**
 * Harness-grade A/B for Tier 4 (HasGreasedSerialization): vanilla vs greased
 * `attributesToArray()` on a timestamps-only model, isolating the
 * `created_at`/`updated_at` Carbon parse-and-reformat round-trip from all other
 * cast work. Compare the paired `*Vanilla` / `*Greased` subjects for the per-op
 * delta the macro (`realworld.php`) sees end-to-end.
 *
 * Pinned to UTC: Laravel's own default, and the zone under which the fast path is
 * certified — so the bench reflects the representative configuration rather than
 * whatever the runner's php.ini happens to be. (Under a non-UTC default serializer
 * the tier safely defers, which a separate test asserts; timing that would just
 * report "no change", which is true but uninteresting here.)
 */
#[
    BeforeMethods('setUp'),
    Warmup(2),
    Revs(1000),
    Iterations(10),
    RetryThreshold(3),
]
class DateSerializationBench
{
    private static bool $booted = false;

    private VanillaTimestamps $vanilla;

    private GreasedTimestamps $greased;

    private VanillaDatetimeCast $vanillaCast;

    private GreasedDatetimeCast $greasedCast;

    public function setUp(): void
    {
        date_default_timezone_set('UTC');

        if (! self::$booted) {
            $capsule = new Capsule;
            $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
            $capsule->setAsGlobal();
            $capsule->bootEloquent();
            self::$booted = true;
        }

        $this->vanilla = (new VanillaTimestamps)->newFromBuilder(SampleData::timestampsRow());
        $this->greased = (new GreasedTimestamps)->newFromBuilder(SampleData::timestampsRow());

        $this->vanillaCast = (new VanillaDatetimeCast)->newFromBuilder(SampleData::datetimeCastRow());
        $this->greasedCast = (new GreasedDatetimeCast)->newFromBuilder(SampleData::datetimeCastRow());

        // Fidelity guard: never time a non-identical state. Parity is proven in
        // DateSerializationParityTest; this just refuses to report a misleading
        // delta if the bench is ever run somewhere the two diverge.
        $this->assertIdentical($this->vanilla, $this->greased);
        $this->assertIdentical($this->vanillaCast, $this->greasedCast);
    }

    private function assertIdentical(object $vanilla, object $greased): void
    {
        if (json_encode($vanilla->toArray()) !== json_encode($greased->toArray())) {
            throw new RuntimeException('Date-serialization bench parity broken — output is not byte-identical.');
        }
    }

    public function benchSerializeDatesVanilla(): void
    {
        $this->vanilla->attributesToArray();
    }

    public function benchSerializeDatesGreased(): void
    {
        $this->greased->attributesToArray();
    }

    public function benchSerializeDatetimeCastsVanilla(): void
    {
        $this->vanillaCast->attributesToArray();
    }

    public function benchSerializeDatetimeCastsGreased(): void
    {
        $this->greasedCast->attributesToArray();
    }
}
