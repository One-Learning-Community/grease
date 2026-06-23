<?php

namespace Grease\Tests;

use Grease\Concerns\HasGrease;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * {@see \Grease\Concerns\HasGreasedSerialization::fromDateTime()} skips the parse-and-reformat
 * round-trip when the value is already a storage-format string under the standard driver format
 * — the write-path twin of the date serialization tier. The contract is byte-for-byte identical
 * to vanilla `fromDateTime()` for every input (storage string, Carbon, null, off-format string,
 * custom `dateFormat`), and identical `getDirty()`/`originalIsEquivalent()` results on `save()`.
 * Vanilla is the oracle.
 */
class HasGreasedFromDateTimeParityTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_fromdatetime_matches_vanilla_across_inputs(): void
    {
        $vanilla = new VanillaFDT;
        $greased = new GreasedFDT;

        $inputs = [
            '2026-01-01 00:00:00',                 // storage string → fast path
            '2026-03-04 09:10:11',
            '1999-02-28 23:59:59',
            null,                                  // empty → vanilla short-circuit
            '',                                    // empty string
            '2026-03-04',                          // date-only, not the SHAPE → defer
            '2026-03-04T09:10:11.000000Z',         // ISO, not the SHAPE → defer
            Carbon::parse('2026-03-04 09:10:11'),  // Carbon → defer
        ];

        foreach ($inputs as $input) {
            $this->assertSame(
                $vanilla->fromDateTime($input),
                $greased->fromDateTime($input),
                'fromDateTime('.var_export(is_object($input) ? get_class($input) : $input, true).')',
            );
        }
    }

    public function test_custom_dateformat_defers_to_vanilla(): void
    {
        // A non-standard driver format is not certified → must defer (and still match vanilla).
        $vanilla = new VanillaFDTCustomFormat;
        $greased = new GreasedFDTCustomFormat;

        $this->assertSame(
            $vanilla->fromDateTime('2026-01-01 00:00:00'),
            $greased->fromDateTime('2026-01-01 00:00:00'),
        );

        // The certify probe must have returned 'defer' for this class.
        $plan = (new \ReflectionMethod($greased, 'greaseFromDateTimePlan'))->invoke($greased);
        $this->assertSame('defer', $plan);
    }

    public function test_getdirty_on_a_saved_date_change_matches_vanilla(): void
    {
        // Integration: the hot caller. A timestamp bump makes updated_at dirty; both sides of the
        // comparison are storage strings post-setAttribute, so vanilla parses both and the fast
        // path parses neither — the dirty set must be identical.
        Carbon::setTestNow('2026-06-01 12:00:00');

        $row = ['id' => 1, 'score' => '10.00', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00'];

        $vanilla = (new VanillaFDT)->newFromBuilder($row);
        $greased = (new GreasedFDT)->newFromBuilder($row);

        $vanilla->updateTimestamps();
        $greased->updateTimestamps();

        $this->assertSame($vanilla->getDirty(), $greased->getDirty());
        $this->assertArrayHasKey('updated_at', $greased->getDirty(), 'updated_at is dirty after bump');
    }

    public function test_unchanged_date_is_not_dirty_like_vanilla(): void
    {
        // An untouched storage-format date must NOT be reported dirty — the fast path returns it
        // unchanged so originalIsEquivalent stays true, exactly as vanilla's parse-reformat does.
        $row = ['id' => 1, 'score' => '10.00', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00'];

        $greased = (new GreasedFDT)->newFromBuilder($row);

        $this->assertArrayNotHasKey('created_at', $greased->getDirty());
        $this->assertSame([], $greased->getDirty());
    }
}

class VanillaFDT extends Model
{
    protected $table = 'fdt';
    protected $casts = ['score' => 'decimal:2', 'created_at' => 'datetime', 'updated_at' => 'datetime'];
}

class GreasedFDT extends Model
{
    use HasGrease;

    protected $table = 'fdt';
    protected $casts = ['score' => 'decimal:2', 'created_at' => 'datetime', 'updated_at' => 'datetime'];
}

class VanillaFDTCustomFormat extends Model
{
    protected $table = 'fdt';
    protected $dateFormat = 'U'; // unix timestamp — not the standard driver format
}

class GreasedFDTCustomFormat extends Model
{
    use HasGrease;

    protected $table = 'fdt';
    protected $dateFormat = 'U';
}
