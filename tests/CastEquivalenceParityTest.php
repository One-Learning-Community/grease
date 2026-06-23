<?php

namespace Grease\Tests;

use Grease\Concerns\HasGrease;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The cast-objects differential matrix, repurposed as a Grease parity gate: every
 * case must agree with BOTH vanilla Eloquent and the documented expectation. The
 * per-case cast is applied via mergeCasts() (the supported runtime path), which
 * also exercises the divergence guard ~40 times over.
 */
class CastEquivalenceParityTest extends TestCase
{
    /**
     * @return array<string, array{string|object, mixed, mixed, bool}>
     */
    public static function dirtyCases(): array
    {
        return [
            // [cast, stored "original" value, freshly set value, expected isDirty]

            'int: string original vs int set' => ['integer', '1', 1, false],
            'int: unchanged' => ['integer', 1, 1, false],
            'int: changed' => ['integer', 1, 2, true],
            'int: zero forms' => ['integer', '0', 0, false],
            'int: null/null' => ['integer', null, null, false],

            'float: string vs float' => ['float', '1.5', 1.5, false],
            'float: int vs float one' => ['float', '1.0', 1, false],
            'float: changed' => ['float', 1.5, 1.6, true],

            'decimal: trailing zero' => ['decimal:2', '1.00', '1.0', false],
            'decimal: string vs int' => ['decimal:2', '1.00', 1, false],
            'decimal: rounds equal' => ['decimal:2', '1.23', '1.234', false],

            'string: string vs int' => ['string', '1', 1, false],
            'string: changed' => ['string', 'a', 'b', true],

            'bool: string vs bool' => ['boolean', '1', true, false],
            'bool: int vs bool' => ['boolean', 1, true, false],
            'bool: falsey' => ['boolean', '0', false, false],
            'bool: changed' => ['boolean', true, false, true],

            'object: same' => ['object', '{"a":1,"b":2}', ['a' => 1, 'b' => 2], false],
            'object: whitespace' => ['object', '{"a": 1, "b": 2}', ['a' => 1, 'b' => 2], false],
            'object: reordered keys' => ['object', '{"a":1,"b":2}', ['b' => 2, 'a' => 1], true],
            'array: same' => ['array', '{"a":1,"b":2}', ['a' => 1, 'b' => 2], false],
            'array: whitespace' => ['array', '{"a": 1, "b": 2}', ['a' => 1, 'b' => 2], false],
            'array: list reordered' => ['array', '[1,2,3]', [3, 2, 1], true],
            'json:unicode same' => ['json:unicode', '{"a":"é"}', ['a' => 'é'], false],
            'collection: same' => ['collection', '[1,2,3]', [1, 2, 3], false],
            'collection: whitespace' => ['collection', '{"a": 1}', ['a' => 1], false],

            'date: equivalent format' => ['date', '2025-01-01', '2025-01-01 00:00:00', false],
            'date: changed' => ['date', '2025-01-01', '2025-01-02', true],
            'datetime: equivalent format' => ['datetime', '2025-01-01', '2025-01-01 00:00:00', false],
            'datetime: changed' => ['datetime', '2025-01-01 12:00:00', '2025-01-01 13:00:00', true],
            'custom datetime: equivalent' => ['datetime:Y-m-d', '2025-01-01', '2025-01-01 00:00:00', false],
            'immutable date: equivalent' => ['immutable_date', '2025-01-01', '2025-01-01 00:00:00', false],
            'immutable datetime: equivalent' => ['immutable_datetime', '2025-01-01', '2025-01-01 00:00:00', false],

            'timestamp: string vs int' => ['timestamp', '1700000000', 1700000000, false],
            'timestamp: changed' => ['timestamp', 1700000000, 1700000001, true],

            'int enum: string original vs case' => [EquivIntEnum::class, '1', EquivIntEnum::A, false],
            'int enum: int original vs case' => [EquivIntEnum::class, 1, EquivIntEnum::A, false],
            'int enum: changed case' => [EquivIntEnum::class, 1, EquivIntEnum::B, true],
            'string enum: same' => [EquivStringEnum::class, 'a', EquivStringEnum::A, false],
            'string enum: changed' => [EquivStringEnum::class, 'a', EquivStringEnum::B, true],

            'custom cast: round trip' => [EquivReverseCast::class, 'cba', 'abc', false],
        ];
    }

    #[DataProvider('dirtyCases')]
    public function test_dirty_equivalence_matches_vanilla_and_expectation($cast, $original, $set, bool $expectedDirty): void
    {
        $vanilla = $this->box(VanillaCastBox::class, $cast, $original);
        $greased = $this->box(GreasedCastBox::class, $cast, $original);

        $vanilla->value = $set;
        $greased->value = $set;

        $this->assertSame($expectedDirty, $vanilla->isDirty('value'), 'vanilla baseline shifted');
        $this->assertSame($expectedDirty, $greased->isDirty('value'), 'grease diverged from the expectation');
        $this->assertSame($vanilla->isDirty('value'), $greased->isDirty('value'), 'grease diverged from vanilla');
        $this->assertSame(
            array_key_exists('value', $vanilla->getDirty()),
            array_key_exists('value', $greased->getDirty()),
        );
    }

    /**
     * @return array<string, array{mixed, mixed, bool}>
     */
    public static function nonCastDateCases(): array
    {
        return [
            'equivalent format' => ['2025-01-01', '2025-01-01 00:00:00', false],
            'same' => ['2025-01-01 12:00:00', '2025-01-01 12:00:00', false],
            'changed' => ['2025-01-01 12:00:00', '2025-01-01 13:00:00', true],
            'null/null' => [null, null, false],
        ];
    }

    #[DataProvider('nonCastDateCases')]
    public function test_non_cast_date_attribute_equivalence($original, $set, bool $expectedDirty): void
    {
        // updated_at is a date attribute via getDates() but is NOT in $casts.
        $vanilla = (new VanillaTimestampBox)->newFromBuilder(['updated_at' => $original]);
        $greased = (new GreasedTimestampBox)->newFromBuilder(['updated_at' => $original]);

        foreach ([$vanilla, $greased] as $model) {
            $attributes = $model->getAttributes();
            $attributes['updated_at'] = $set;
            $model->setRawAttributes($attributes, false);
        }

        $this->assertSame($expectedDirty, $greased->isDirty('updated_at'));
        $this->assertSame($vanilla->isDirty('updated_at'), $greased->isDirty('updated_at'));
    }

    public function test_get_cast_type_override_is_honored_on_greased_models(): void
    {
        // Grease resolves the cast type via getCastType(), so an override IS
        // honored — unlike the maximally-narrowed design. (The actual narrowing
        // is per-key isEncryptedCastable, exercised elsewhere.)
        $vanilla = (new VanillaCastTypeOverride)->newFromBuilder(['flag' => '1']);
        $greased = (new GreasedCastTypeOverride)->newFromBuilder(['flag' => '1']);

        $this->assertTrue($greased->flag);
        $this->assertSame($vanilla->flag, $greased->flag);

        $greased->flag = '0';
        $this->assertFalse($greased->flag);
    }

    private function box(string $class, $cast, $original): Model
    {
        $model = new $class;
        $model->mergeCasts(['value' => $cast]);
        $model->setRawAttributes(['value' => $original], true);

        return $model;
    }
}

enum EquivIntEnum: int
{
    case A = 1;
    case B = 2;
}

enum EquivStringEnum: string
{
    case A = 'a';
    case B = 'b';
}

class EquivReverseCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        return is_null($value) ? null : strrev((string) $value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        return is_null($value) ? null : strrev((string) $value);
    }
}

class VanillaCastBox extends Model
{
    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s';

    public $timestamps = false;

    protected $table = 'boxes';
}

class GreasedCastBox extends VanillaCastBox
{
    use HasGrease;
}

class VanillaTimestampBox extends Model
{
    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s';

    public $timestamps = true;

    protected $table = 'boxes';
}

class GreasedTimestampBox extends VanillaTimestampBox
{
    use HasGrease;
}

class VanillaCastTypeOverride extends Model
{
    protected $guarded = [];

    public $timestamps = false;

    protected $table = 'boxes';

    protected $casts = ['flag' => 'string'];

    protected function getCastType($key)
    {
        return $key === 'flag' ? 'boolean' : parent::getCastType($key);
    }
}

class GreasedCastTypeOverride extends VanillaCastTypeOverride
{
    use HasGrease;
}
