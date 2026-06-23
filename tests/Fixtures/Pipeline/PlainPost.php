<?php

namespace Grease\Tests\Fixtures\Pipeline;

use Illuminate\Database\Eloquent\Model;

/**
 * Vanilla post model for the cumulative-stack pipeline benchmark.
 */
class PlainPost extends Model
{
    protected $table = 'posts';

    protected $casts = [
        'view_count' => 'integer',
        'is_published' => 'boolean',
        'published_at' => 'datetime',
        'meta' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(PlainUser::class, 'user_id');
    }
}
