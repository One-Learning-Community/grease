<?php

namespace Grease\Tests;

use Grease\Concerns\HasGrease;
use Grease\Tests\Fixtures\Status;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use PHPUnit\Framework\Attributes\DataProvider;

class VanillaEncrypted extends Model
{
    protected $table = 'enc';

    protected $casts = ['secret' => 'encrypted', 'secret_list' => 'encrypted:array'];
}

class GreasedEncrypted extends Model
{
    use HasGrease;

    protected $table = 'enc';

    protected $casts = ['secret' => 'encrypted', 'secret_list' => 'encrypted:array'];
}

/**
 * Every cast type a greased model can encounter must produce output byte-identical
 * to vanilla Eloquent — values, types, nulls, and serialization.
 */
class CastParityTest extends TestCase
{
    public function test_full_row_toarray_is_identical(): void
    {
        [$v, $g] = $this->pair($this->sampleRow());

        // json_encode: strict on values/types/structure, but agnostic to the
        // object identity of `object`/`collection` cast instances (which are
        // correctly separate-but-equal between the two models).
        $this->assertSame(json_encode($v->toArray()), json_encode($g->toArray()));
    }

    #[DataProvider('castColumnProvider')]
    public function test_each_cast_read_is_identical(string $col): void
    {
        [$v, $g] = $this->pair($this->sampleRow());

        $this->assertEquals($v->{$col}, $g->{$col}, "value mismatch on [$col]");
        $this->assertSame(get_debug_type($v->{$col}), get_debug_type($g->{$col}), "type mismatch on [$col]");
    }

    public static function castColumnProvider(): array
    {
        return array_map(fn ($c) => [$c], [
            'int_val', 'real_val', 'float_val', 'dec_val', 'str_val', 'bool_val',
            'obj_val', 'arr_val', 'json_val', 'coll_val', 'date_val', 'dt_val',
            'cdt_val', 'imm_date_val', 'imm_dt_val', 'icdt_val', 'ts_val',
            'hashed_val', 'status_val', 'upper_val', 'created_at', 'updated_at',
        ]);
    }

    public function test_all_null_casts_are_identical(): void
    {
        $nulls = array_fill_keys(array_diff(array_keys($this->sampleRow()), ['id']), null);

        [$v, $g] = $this->pair($this->sampleRow($nulls));

        $this->assertSame($v->toArray(), $g->toArray());

        foreach ($this->castColumns() as $col) {
            $this->assertSame($v->{$col}, $g->{$col}, "null mismatch on [$col]");
        }
    }

    public function test_enum_and_custom_class_casts_match(): void
    {
        [$v, $g] = $this->pair($this->sampleRow());

        $this->assertSame(Status::Active, $g->status_val);
        $this->assertSame($v->status_val, $g->status_val);

        $this->assertSame('HELLO', $g->upper_val);
        $this->assertSame($v->upper_val, $g->upper_val);
    }

    public function test_encrypted_casts_defer_identically(): void
    {
        $row = [
            'id' => 1,
            'secret' => Crypt::encryptString('top-secret'),
            'secret_list' => Crypt::encryptString(json_encode(['a' => 1, 'b' => 2])),
        ];

        $v = (new VanillaEncrypted)->newFromBuilder($row);
        $g = (new GreasedEncrypted)->newFromBuilder($row);

        $this->assertSame('top-secret', $g->secret);
        $this->assertSame($v->secret, $g->secret);
        $this->assertSame(['a' => 1, 'b' => 2], $g->secret_list);
        $this->assertSame($v->secret_list, $g->secret_list);
    }
}
