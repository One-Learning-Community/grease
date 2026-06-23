<?php

namespace Grease\Tests\Fixtures\Pipeline;

use Grease\Concerns\HasGrease;

/**
 * Greased user — same table as {@see PlainUser}, plus HasGrease and relations within the
 * greased set.
 */
class GreasedUser extends PlainUser
{
    use HasGrease;

    public function posts()
    {
        return $this->hasMany(GreasedPost::class, 'user_id');
    }
}
