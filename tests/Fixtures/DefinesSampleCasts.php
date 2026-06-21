<?php

namespace Grease\Tests\Fixtures;

/**
 * One cast map shared by the vanilla and greased sample models (via the modern
 * casts() method, so the only difference between the two models is the Grease
 * traits). Covers every distinct built-in flyweight branch (+ aliases), plus the
 * cast kinds Grease defers to the framework: enum and custom-class. (Encrypted is
 * exercised separately — it needs a ciphertext value.)
 */
trait DefinesSampleCasts
{
    protected function casts(): array
    {
        return [
            'int_val' => 'integer',
            'real_val' => 'real',
            'float_val' => 'double',
            'dec_val' => 'decimal:2',
            'str_val' => 'string',
            'bool_val' => 'boolean',
            'obj_val' => 'object',
            'arr_val' => 'array',
            'json_val' => 'json',
            'coll_val' => 'collection',
            'date_val' => 'date',
            'dt_val' => 'datetime',
            'cdt_val' => 'datetime:Y-m-d',
            'imm_date_val' => 'immutable_date',
            'imm_dt_val' => 'immutable_datetime',
            'icdt_val' => 'immutable_datetime:Y-m-d',
            'ts_val' => 'timestamp',
            'hashed_val' => 'hashed',
            'status_val' => Status::class,
            'upper_val' => UpperCast::class,
        ];
    }
}
