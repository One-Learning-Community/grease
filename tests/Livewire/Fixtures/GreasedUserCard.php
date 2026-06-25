<?php

namespace Grease\Tests\Livewire\Fixtures;

use Grease\Tests\Fixtures\Pipeline\GreasedUser;

/** Serializes a greased model into the snapshot — the side under test. */
class GreasedUserCard extends UserCard
{
    protected function model(): string
    {
        return GreasedUser::class;
    }
}
