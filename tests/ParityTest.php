<?php

namespace Grease\Tests;

use Grease\Concerns\HasGrease;
use Illuminate\Database\Eloquent\Model;

class VanillaWidget extends Model
{
    protected $table = 'widgets';

    protected $casts = [
        'count' => 'integer', 'active' => 'boolean', 'amount' => 'decimal:2',
        'payload' => 'array', 'happened_at' => 'datetime',
    ];
}

class GreasedWidget extends Model
{
    use HasGrease;

    protected $table = 'widgets';

    protected $casts = [
        'count' => 'integer', 'active' => 'boolean', 'amount' => 'decimal:2',
        'payload' => 'array', 'happened_at' => 'datetime',
    ];
}

/**
 * The package's spine: a greased model must be byte-identical to vanilla on
 * output, only faster. If a future tier ever diverges, this fails loudly.
 */
class ParityTest extends TestCase
{
    public function test_toarray_is_identical(): void
    {
        $row = $this->rawRow();

        $vanilla = (new VanillaWidget)->newFromBuilder($row);
        $greased = (new GreasedWidget)->newFromBuilder($row);

        $this->assertSame($vanilla->toArray(), $greased->toArray());
    }

    public function test_individual_cast_reads_are_identical(): void
    {
        $vanilla = (new VanillaWidget)->newFromBuilder($this->rawRow());
        $greased = (new GreasedWidget)->newFromBuilder($this->rawRow());

        foreach (['count', 'active', 'amount', 'payload', 'happened_at'] as $key) {
            $this->assertEquals($vanilla->{$key}, $greased->{$key}, "cast mismatch on [$key]");
            $this->assertSame(get_debug_type($vanilla->{$key}), get_debug_type($greased->{$key}), "type mismatch on [$key]");
        }
    }

    public function test_null_values_are_identical(): void
    {
        $row = $this->rawRow(['happened_at' => null, 'payload' => null]);

        $vanilla = (new VanillaWidget)->newFromBuilder($row);
        $greased = (new GreasedWidget)->newFromBuilder($row);

        $this->assertSame($vanilla->toArray(), $greased->toArray());
    }

    public function test_dirty_tracking_is_identical(): void
    {
        $vanilla = (new VanillaWidget)->newFromBuilder($this->rawRow());
        $greased = (new GreasedWidget)->newFromBuilder($this->rawRow());

        // Re-assigning an equivalent value must not mark either model dirty.
        $vanilla->amount = '12.34';
        $greased->amount = '12.34';
        $this->assertSame($vanilla->isDirty(), $greased->isDirty());

        // A real change must be reported identically.
        $vanilla->count = 99;
        $greased->count = 99;
        $this->assertSame($vanilla->getDirty(), $greased->getDirty());
    }

    public function test_resolved_config_matches(): void
    {
        $vanilla = new VanillaWidget;
        $greased = new GreasedWidget;

        $this->assertSame($vanilla->getCasts(), $greased->getCasts());
        $this->assertSame($vanilla->getDateFormat(), $greased->getDateFormat());
        $this->assertSame($vanilla->getKeyName(), $greased->getKeyName());
        $this->assertSame($vanilla->getIncrementing(), $greased->getIncrementing());
    }
}
