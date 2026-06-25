<?php

namespace Grease\Tests\Livewire\Fixtures;

use Grease\Tests\Fixtures\Pipeline\PlainUser;

/** Holds a vanilla model — the oracle. */
class VanillaShowUser extends ShowUser
{
    protected function model(): string
    {
        return PlainUser::class;
    }
}
