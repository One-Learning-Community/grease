<?php

namespace Grease\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

class VanillaSample extends Model
{
    use DefinesSampleCasts;

    protected $table = 'samples';
}
