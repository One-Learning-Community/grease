<?php

namespace Grease\Tests\Fixtures;

use Grease\Concerns\HasGrease;
use Illuminate\Database\Eloquent\Model;

/**
 * The greased counterpart to {@see VanillaTimestamps} — identical but for the
 * Grease traits, so a bench over the pair isolates the Tier 4 date-serialization
 * win and a parity test proves the two produce byte-identical output.
 */
class GreasedTimestamps extends Model
{
    use HasGrease;

    protected $table = 'timestamps_sample';
}
