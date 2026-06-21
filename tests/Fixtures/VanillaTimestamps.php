<?php

namespace Grease\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * A minimal timestamps-only model — no casts, just `created_at`/`updated_at` — so
 * the Tier 4 date-serialization win can be measured (and proven identical) in
 * isolation, undiluted by other cast work. Paired with {@see GreasedTimestamps}.
 */
class VanillaTimestamps extends Model
{
    protected $table = 'timestamps_sample';
}
