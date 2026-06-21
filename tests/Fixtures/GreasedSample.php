<?php

namespace Grease\Tests\Fixtures;

use Grease\Concerns\HasGrease;
use Illuminate\Database\Eloquent\Model;

class GreasedSample extends Model
{
    use DefinesSampleCasts;
    use HasGrease;

    protected $table = 'samples';
}
