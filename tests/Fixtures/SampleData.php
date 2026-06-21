<?php

namespace Grease\Tests\Fixtures;

/**
 * The one raw, database-shaped row (driver-style strings) shared by the test
 * suite and the benchmarks, so the bench exercises exactly what the tests prove.
 */
class SampleData
{
    /**
     * @return array<string, mixed>
     */
    public static function row(): array
    {
        return [
            'id' => 1,
            'int_val' => '42',
            'real_val' => '2.5',
            'float_val' => '3.14159',
            'dec_val' => '12.34',
            'str_val' => 100,
            'bool_val' => '1',
            'obj_val' => '{"x":1,"y":2}',
            'arr_val' => '{"a":[1,2],"b":3}',
            'json_val' => '[1,2,3]',
            'coll_val' => '[4,5,6]',
            'date_val' => '2026-03-04 09:10:11',
            'dt_val' => '2026-03-04 09:10:11',
            'cdt_val' => '2026-03-04 09:10:11',
            'imm_date_val' => '2026-03-04 09:10:11',
            'imm_dt_val' => '2026-03-04 09:10:11',
            'icdt_val' => '2026-03-04 09:10:11',
            'ts_val' => '2026-03-04 09:10:11',
            'hashed_val' => 'stored-as-is-on-read',
            'status_val' => 'active',
            'upper_val' => 'hello',
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ];
    }

    /**
     * A minimal timestamps-only row (two distinct, non-midnight timestamps) shared
     * by the Tier 4 parity test and the date-serialization bench, so the bench
     * times exactly the round-trip a test proves byte-identical.
     *
     * @return array<string, mixed>
     */
    public static function timestampsRow(): array
    {
        return [
            'id' => 1,
            'name' => 'widget',
            'created_at' => '2026-03-04 09:10:11',
            'updated_at' => '2024-12-31 23:59:59',
        ];
    }

    /**
     * A row for the two plain datetime casts, shared by the Tier 4 cast-path parity
     * test and bench so the bench times exactly the cast round-trip a test proves
     * byte-identical.
     *
     * @return array<string, mixed>
     */
    public static function datetimeCastRow(): array
    {
        return [
            'id' => 1,
            'published_at' => '2026-03-04 09:10:11',
            'archived_at' => '2024-12-31 23:59:59',
        ];
    }
}
