<?php

namespace Grease\Tests;

use Grease\Concerns\HasGrease;
use Grease\Tests\Fixtures\Priority;
use Grease\Tests\Fixtures\Status;
use Grease\Tests\Fixtures\Suit;
use Grease\Tests\Fixtures\UpperCast;
use Illuminate\Database\Eloquent\Model;
use ReflectionProperty;

class VanillaEnums extends Model
{
    protected $table = 'enums';

    protected $casts = [
        'status' => Status::class,
        'priority' => Priority::class,
        'suit' => Suit::class,
        'upper' => UpperCast::class,
    ];
}

class GreasedEnums extends Model
{
    use HasGrease;

    protected $table = 'enums';

    protected $casts = [
        'status' => Status::class,
        'priority' => Priority::class,
        'suit' => Suit::class,
        'upper' => UpperCast::class,
    ];
}

/**
 * The enum fast path (HasGreasedCasts) must be byte-/value-identical to vanilla
 * across every enum flavour — backed-string, backed-int, and pure/unit — plus the
 * already-an-instance, null, and invalid-value cases. The conversion is delegated
 * to the framework's getEnumCastableAttributeValue(), so these prove the delegation
 * holds where a reimplementation would diverge (notably the pure enum, which uses
 * constant() rather than ::from()).
 */
class EnumCastParityTest extends TestCase
{
    private function enumPair(array $overrides = []): array
    {
        $row = array_merge([
            'id' => 1,
            'status' => 'active',
            'priority' => 2,
            'suit' => 'Hearts',
            'upper' => 'hello',
        ], $overrides);

        return [
            (new VanillaEnums)->newFromBuilder($row),
            (new GreasedEnums)->newFromBuilder($row),
        ];
    }

    public function test_backed_string_enum_matches(): void
    {
        [$v, $g] = $this->enumPair();

        $this->assertSame(Status::Active, $g->status);
        $this->assertSame($v->status, $g->status);
    }

    public function test_backed_int_enum_matches(): void
    {
        [$v, $g] = $this->enumPair();

        $this->assertSame(Priority::High, $g->priority);
        $this->assertSame($v->priority, $g->priority);
    }

    public function test_pure_unit_enum_matches(): void
    {
        [$v, $g] = $this->enumPair();

        // Pure enums convert via constant("Suit::Hearts"), not ::from().
        $this->assertSame(Suit::Hearts, $g->suit);
        $this->assertSame($v->suit, $g->suit);
    }

    public function test_null_enum_matches(): void
    {
        [$v, $g] = $this->enumPair(['status' => null, 'priority' => null, 'suit' => null]);

        $this->assertNull($g->status);
        $this->assertNull($g->priority);
        $this->assertNull($g->suit);
        $this->assertSame($v->toArray(), $g->toArray());
    }

    public function test_already_an_instance_read_matches(): void
    {
        [$v, $g] = $this->enumPair();

        // Assigning an enum instance, then reading it back, must short-circuit
        // identically (the `$value instanceof $castType` branch).
        $v->status = Status::Inactive;
        $g->status = Status::Inactive;

        $this->assertSame(Status::Inactive, $g->status);
        $this->assertSame($v->status, $g->status);
    }

    public function test_toarray_and_json_match(): void
    {
        [$v, $g] = $this->enumPair();

        $this->assertSame($v->toArray(), $g->toArray());
        $this->assertSame($v->toJson(), $g->toJson());
    }

    public function test_invalid_backed_value_throws_identical_error(): void
    {
        [$v, $g] = $this->enumPair(['status' => 'bogus']);

        $vError = null;
        $gError = null;

        try {
            $v->status;
        } catch (\ValueError $e) {
            $vError = $e->getMessage();
        }

        try {
            $g->status;
        } catch (\ValueError $e) {
            $gError = $e->getMessage();
        }

        $this->assertNotNull($vError, 'vanilla should throw ValueError');
        $this->assertSame($vError, $gError, 'greased must throw the identical ValueError');
    }

    public function test_dirty_tracking_is_unaffected_by_read_fast_path(): void
    {
        [, $g] = $this->enumPair();

        // Reading routes through the enum fast path; dirty must still be a raw-scalar
        // compare, untouched by it.
        $g->status;
        $this->assertFalse($g->isDirty('status'));

        $g->status = Status::Active; // same case it already holds
        $this->assertFalse($g->isDirty('status'), 'setting the current case is not dirty');

        $g->status = Status::Inactive;
        $this->assertTrue($g->isDirty('status'), 'changing the case is dirty');
    }

    public function test_fast_path_is_engaged_and_custom_class_still_defers(): void
    {
        [, $g] = $this->enumPair();

        // Touch an enum read and a custom-class read.
        $g->priority;
        $g->upper;

        $map = (new ReflectionProperty(GreasedEnums::class, 'greaseEnumTypes'))->getValue();

        // The enum type is certified true (fast path taken)...
        $this->assertTrue($map[Priority::class] ?? null, 'enum cast type should be marked as an enum');
        // ...while the custom-class cast is certified false (it defers to parent::).
        $this->assertFalse($map[UpperCast::class] ?? null, 'custom-class cast must not be treated as an enum');
    }
}
