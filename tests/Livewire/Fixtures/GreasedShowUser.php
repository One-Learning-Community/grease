<?php

namespace Grease\Tests\Livewire\Fixtures;

use Grease\Tests\Fixtures\Pipeline\GreasedUser;

/** Holds a greased model — the side under test. */
class GreasedShowUser extends ShowUser
{
    protected function model(): string
    {
        return GreasedUser::class;
    }
}
