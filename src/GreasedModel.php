<?php

namespace Grease;

use Grease\Concerns\HasGrease;
use Illuminate\Database\Eloquent\Model;

/**
 * A drop-in base model with every Grease tier applied — for when you'd rather
 * extend than reach for a trait.
 *
 *   class User extends \Grease\GreasedModel { ... }
 *
 * Identical in behavior to `extends Model; use HasGrease;`.
 */
abstract class GreasedModel extends Model
{
    use HasGrease;
}
