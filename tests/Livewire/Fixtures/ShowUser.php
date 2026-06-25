<?php

namespace Grease\Tests\Livewire\Fixtures;

use Livewire\Component;

/**
 * Shared Livewire component for the parity contract: holds an Eloquent user as a public
 * property (so Livewire dehydrates it into the snapshot between requests), renders its
 * date/cast attributes (so the serialized output lands in the HTML), and exposes an action
 * that mutates a cast column.
 *
 * The two concrete subclasses differ in ONE thing — the model class ({@see GreasedShowUser}
 * holds a greased model, {@see VanillaShowUser} a vanilla one) — so any divergence in the
 * dehydrated snapshot, its checksum, or the rendered HTML is attributable solely to the
 * model axis. Everything else (template, lifecycle, Livewire machinery) is held constant.
 */
abstract class ShowUser extends Component
{
    public $user;

    public int $bumps = 0;

    /** @return class-string */
    abstract protected function model(): string;

    public function mount(int $id): void
    {
        $this->user = ($this->model())::query()->findOrFail($id);
    }

    /** Mutate a `decimal:2` cast column — exercises dirty-tracking through the synth. */
    public function bump(): void
    {
        $this->bumps++;
        $this->user->score = number_format((float) $this->user->score + 1, 2, '.', '');
    }

    public function render()
    {
        return <<<'BLADE'
        <div>
            <span class="name">{{ $user->name }}</span>
            <span class="verified">{{ $user->email_verified_at }}</span>
            <span class="created">{{ $user->created_at }}</span>
            <span class="score">{{ $user->score }}</span>
            <span class="bumps">{{ $bumps }}</span>
        </div>
        BLADE;
    }
}
