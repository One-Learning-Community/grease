<?php

namespace Grease\Tests\Livewire\Fixtures;

use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Shared Livewire component for the parity contract: holds an Eloquent user as a public
 * property (so Livewire dehydrates it into the snapshot between requests), renders its
 * date/cast attributes (so the serialized output lands in the HTML), and exposes an action
 * that mutates a cast column.
 *
 * It also lists the user's posts through a `#[Computed]` property — the idiomatic Livewire way
 * to surface a queried collection to the view. A computed is re-evaluated on every request
 * (memoized only within a single render), so it re-queries on every update — which means the
 * update path actively hydrates the user AND its posts. That's the shape where Grease's
 * per-`new Model()` win recurs on every interaction (a data table sorting/paginating/filtering
 * behaves the same way), rather than landing only on first paint.
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

    /** The user's recent posts — queried fresh each request, the idiomatic computed-property way. */
    #[Computed]
    public function posts()
    {
        return $this->user->posts()->orderBy('id')->limit(8)->get();
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
            @foreach ($this->posts as $post)
                <span class="post">{{ $post->published_at }}{{ $post->view_count }}{{ $post->is_published }}</span>
            @endforeach
        </div>
        BLADE;
    }
}
