<?php

namespace Grease\Tests\Livewire\Fixtures;

use Livewire\Component;

/**
 * The serialization-heavy counterpart to {@see ShowUser}.
 *
 * Where ShowUser holds the model itself (which Livewire dehydrates to a bare `{class,key}`
 * and re-queries), this holds the model's `toArray()` output as a plain `data` property — a
 * common Livewire pattern for passing a read model to the front end without a re-query. That
 * array carries the FULL serialized output: ISO-8601 dates (`...T00:00:00.000000Z`, the date
 * tier), the `decimal:2` string, the cast `settings` array, the loaded `posts` relation. All
 * of it lands inside Livewire's snapshot `data` and is sealed by the HMAC checksum.
 *
 * So this is the test with teeth for the snapshot/checksum claim: if a greased model's
 * serialization diverged from vanilla by one byte, this snapshot — and its checksum — would
 * diverge. The two subclasses differ only in the source model class.
 */
abstract class UserCard extends Component
{
    /** @var array<string, mixed> */
    public array $data = [];

    /** @return class-string */
    abstract protected function model(): string;

    public function mount(int $id): void
    {
        $this->data = ($this->model())::with('posts')->findOrFail($id)->toArray();
    }

    public function render()
    {
        return <<<'BLADE'
        <div>
            <span class="verified">{{ $data['email_verified_at'] }}</span>
            <span class="score">{{ $data['score'] }}</span>
            <span class="posts">{{ count($data['posts']) }}</span>
        </div>
        BLADE;
    }
}
