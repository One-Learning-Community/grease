<?php

namespace Grease\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * A model whose only work is two plain datetime casts — `datetime` and
 * `immutable_datetime` — so the Tier 4 cast-path win (`addCastAttributesToArray`)
 * is measured (and proven identical) in isolation. Paired with
 * {@see GreasedDatetimeCast}. Timestamps off, to keep the timestamp path out of it.
 */
class VanillaDatetimeCast extends Model
{
    public $timestamps = false;

    protected $table = 'datetime_cast_sample';

    protected $casts = [
        'published_at' => 'datetime',
        'archived_at' => 'immutable_datetime',
    ];
}
