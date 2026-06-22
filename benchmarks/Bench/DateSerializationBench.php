<?php

namespace Grease\Bench;

use Grease\Bench\Support\BootsEloquent;
use Grease\Tests\Fixtures\GreasedDatetimeCast;
use Grease\Tests\Fixtures\GreasedTimestamps;
use Grease\Tests\Fixtures\SampleData;
use Grease\Tests\Fixtures\VanillaDatetimeCast;
use Grease\Tests\Fixtures\VanillaTimestamps;
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
    private VanillaTimestamps $vanilla;

    private GreasedTimestamps $greased;

    private VanillaDatetimeCast $vanillaCast;

    private GreasedDatetimeCast $greasedCast;

    public function setUp(): void
    {
        date_default_timezone_set('UTC');

        BootsEloquent::capsule();

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

    // ── The public primitive vs the hand-pick it replaces ─────────────────────
    //
    // What a Scout `toSearchableArray` / `JsonResource` writes today is a
    // per-attribute date access: `$model->created_at?->toJSON()` — which forces the
    // cast's Carbon parse on every field. `greaseSerializeDate()` is the drop-in.
    //
    // Both subjects hydrate the SAME fresh greased model per rev, so the hydration
    // cost is identical and cancels in the delta — what's left is purely the
    // primitive's Carbon-parse elimination vs the idiomatic access, not the
    // hydration tier. Fresh per rev is mandatory: a re-read measures an
    // already-parsed Carbon (the cast cache) and understates the win to near zero;
    // the parse is paid once per instance, which is exactly once per serialized row.

    public function benchHandPickDatesIdiomatic(): void
    {
        $m = (new GreasedTimestamps)->newFromBuilder(SampleData::timestampsRow());

        $picked = [
            'created_at' => $m->created_at?->toJSON(),
            'updated_at' => $m->updated_at?->toJSON(),
        ];
    }

    public function benchHandPickDatesPrimitive(): void
    {
        $m = (new GreasedTimestamps)->newFromBuilder(SampleData::timestampsRow());

        $picked = [
            'created_at' => $m->greaseSerializeDate('created_at'),
            'updated_at' => $m->greaseSerializeDate('updated_at'),
        ];
    }
}
