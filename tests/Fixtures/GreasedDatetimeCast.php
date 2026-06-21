<?php

namespace Grease\Tests\Fixtures;

use Grease\Concerns\HasGrease;
use Illuminate\Database\Eloquent\Model;

/**
 * The greased counterpart to {@see VanillaDatetimeCast} — identical but for the
 * Grease traits, so a bench over the pair isolates the Tier 4 datetime-cast win
 * and a parity test proves the two produce byte-identical output.
 */
class GreasedDatetimeCast extends Model
{
    use HasGrease;

    public $timestamps = false;

    protected $table = 'datetime_cast_sample';

    protected $casts = [
        'published_at' => 'datetime',
        'archived_at' => 'immutable_datetime',
    ];
}
