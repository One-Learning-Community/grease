<?php

namespace Grease\Tests\Fixtures\Pipeline;

use Illuminate\Database\Eloquent\Model;

/**
 * Vanilla user model for the cumulative-stack pipeline benchmark. Same table + casts as
 * realworld.php; {@see GreasedUser} adds HasGrease on top.
 */
class PlainUser extends Model
{
    protected $table = 'users';

    protected $casts = [
        'age' => 'integer',
        'is_active' => 'boolean',
        'score' => 'decimal:2',
        'settings' => 'array',
        'email_verified_at' => 'datetime',
    ];

    public function posts()
    {
        return $this->hasMany(PlainPost::class, 'user_id');
    }
}
