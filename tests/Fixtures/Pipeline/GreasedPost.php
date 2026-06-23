<?php

namespace Grease\Tests\Fixtures\Pipeline;

use Grease\Concerns\HasGrease;

/**
 * Greased post — same table as {@see PlainPost}, plus HasGrease.
 */
class GreasedPost extends PlainPost
{
    use HasGrease;

    public function user()
    {
        return $this->belongsTo(GreasedUser::class, 'user_id');
    }
}
