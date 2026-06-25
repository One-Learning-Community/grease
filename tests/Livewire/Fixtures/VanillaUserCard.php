<?php

namespace Grease\Tests\Livewire\Fixtures;

use Grease\Tests\Fixtures\Pipeline\PlainUser;

/** Serializes a vanilla model into the snapshot — the oracle. */
class VanillaUserCard extends UserCard
{
    protected function model(): string
    {
        return PlainUser::class;
    }
}
