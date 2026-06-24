<?php

namespace Grease\Tests;

use Grease\Concerns\HasGreasedInitializers;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Touches;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Attributes\Visible;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;

/**
 * {@see HasGreasedInitializers} overrides the four surviving `initialize*` trait booters
 * (guards / hides / timestamps / touches) with a per-class freeze: cold path runs `parent::`
 * once and snapshots the resulting properties; every warm instance applies the snapshot by
 * copy, skipping the `resolveClassAttribute()` calls. The contract is that a greased model's
 * post-construction init state is byte-for-byte what vanilla leaves behind — for plain models,
 * for each class attribute (`#[Fillable]`/`#[Guarded]`/`#[Unguarded]`/`#[Hidden]`/`#[Visible]`/
 * `#[WithoutTimestamps]`/`#[Touches]`/`#[Table]` timestamps), up an STI chain, after runtime
 * mutation, and alongside a user trait's own initializer. Vanilla is the oracle: identical
 * attributes/properties on a vanilla and a greased class, init state compared.
 */
class HasGreasedInitializersParityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The four booters this tier freezes only exist on a framework with the model
        // PHP-attribute feature (#[Fillable]/#[Table]/…). On an older Laravel the tier is
        // inert (it defers to vanilla, which has nothing to run), so there's nothing to
        // assert — skip rather than compare two no-ops.
        if (! method_exists(Model::class, 'resolveClassAttribute')) {
            $this->markTestSkipped('Model PHP attributes require a newer Laravel; this tier is inert here.');
        }
    }

    /** The full init-state surface the four booters write. */
    private function state(Model $m): array
    {
        return [
            'fillable' => $m->getFillable(),
            'guarded' => $m->getGuarded(),
            'hidden' => $m->getHidden(),
            'visible' => $m->getVisible(),
            'timestamps' => $m->usesTimestamps(),
            'touches' => $m->getTouchedRelations(),
        ];
    }

    public function test_plain_model_init_state_matches_vanilla(): void
    {
        $this->assertSame($this->state(new VanillaInitPlain), $this->state(new GreasedInitPlain));
    }

    public function test_every_attribute_init_state_matches_vanilla(): void
    {
        $this->assertSame($this->state(new VanillaInitFull), $this->state(new GreasedInitFull));
    }

    public function test_unguarded_attribute_clears_guarded_like_vanilla(): void
    {
        // #[Unguarded] sets $this->guarded = [] (a property write, NOT static unguard()).
        $this->assertSame([], (new GreasedInitUnguarded)->getGuarded());
        $this->assertSame(
            (new VanillaInitUnguarded)->getGuarded(),
            (new GreasedInitUnguarded)->getGuarded(),
        );
    }

    public function test_warm_instance_matches_cold_instance(): void
    {
        // First instance takes the cold path (snapshots); the second applies the snapshot by
        // copy. Both must equal vanilla — proving the freeze reproduces, not approximates.
        $cold = $this->state(new GreasedInitFull);
        $warm = $this->state(new GreasedInitFull);
        $vanilla = $this->state(new VanillaInitFull);

        $this->assertSame($vanilla, $cold);
        $this->assertSame($vanilla, $warm);
    }

    public function test_sti_subclass_does_not_share_a_snapshot(): void
    {
        // The child overrides fillable/hidden/timestamps; keyed by static::class, it must get
        // its own snapshot and never inherit the parent's frozen state.
        $this->assertSame($this->state(new VanillaInitParent), $this->state(new GreasedInitParent));
        $this->assertSame($this->state(new VanillaInitChild), $this->state(new GreasedInitChild));

        // And the two greased classes genuinely differ (no cross-contamination).
        $this->assertNotSame(
            $this->state(new GreasedInitParent),
            $this->state(new GreasedInitChild),
        );
    }

    public function test_runtime_mutation_after_construction_behaves_like_vanilla(): void
    {
        // The freeze is init-time only; runtime mergeFillable / setHidden / toggling timestamps
        // mutate the instance and must behave exactly as on a vanilla model.
        $vanilla = new VanillaInitFull;
        $greased = new GreasedInitFull;

        $vanilla->mergeFillable(['extra']);
        $greased->mergeFillable(['extra']);
        $vanilla->setHidden(['only']);
        $greased->setHidden(['only']);
        $vanilla->timestamps = false;
        $greased->timestamps = false;

        $this->assertSame($this->state($vanilla), $this->state($greased));

        // A fresh instance constructed afterward must still match vanilla (no leaked mutation).
        $this->assertSame($this->state(new VanillaInitFull), $this->state(new GreasedInitFull));
    }

    public function test_runtime_unguard_static_is_untouched_by_the_freeze(): void
    {
        // unguard() flips a static the booter never writes; getGuarded() reads it. The freeze
        // must not interfere with that orthogonal runtime toggle: greased tracks vanilla in both
        // states, and getGuarded() returns [] while unguarded regardless of the frozen value.
        GreasedInitFull::unguard();
        try {
            $this->assertSame([], (new GreasedInitFull)->getGuarded(), 'unguarded → empty guarded');
            $this->assertSame((new VanillaInitFull)->getGuarded(), (new GreasedInitFull)->getGuarded());
        } finally {
            GreasedInitFull::reguard();
        }

        // After reguard the frozen guarded value returns, matching vanilla.
        $this->assertSame((new VanillaInitFull)->getGuarded(), (new GreasedInitFull)->getGuarded());
    }

    public function test_user_trait_initializer_runs_alongside_the_freeze(): void
    {
        // A user trait's own initialize* booter must still run (initializeTraits dispatches it),
        // untouched by the four overrides.
        $m = new GreasedInitWithUserTrait;

        $this->assertTrue($m->userInitRan, 'user trait initializer must still fire');
        $this->assertSame($this->state(new VanillaInitFull), $this->state($m));
    }
}

// --- Fixtures: identical attributes/properties on a vanilla (oracle) and a greased class. ---

class VanillaInitPlain extends Model {}
class GreasedInitPlain extends Model
{
    use HasGreasedInitializers;
}

#[Table(name: 'init_widgets', timestamps: false)]
#[Fillable(['name', 'qty'])]
#[Guarded(['secret'])]
#[Hidden(['secret'])]
#[Visible(['name', 'qty'])]
#[Touches(['owner'])]
class VanillaInitFull extends Model {}

#[Table(name: 'init_widgets', timestamps: false)]
#[Fillable(['name', 'qty'])]
#[Guarded(['secret'])]
#[Hidden(['secret'])]
#[Visible(['name', 'qty'])]
#[Touches(['owner'])]
class GreasedInitFull extends Model
{
    use HasGreasedInitializers;
}

#[Unguarded]
class VanillaInitUnguarded extends Model {}

#[Unguarded]
class GreasedInitUnguarded extends Model
{
    use HasGreasedInitializers;
}

#[WithoutTimestamps]
#[Fillable(['a'])]
#[Hidden(['h'])]
class VanillaInitParent extends Model {}
class VanillaInitChild extends VanillaInitParent
{
    protected $fillable = ['c'];

    protected $hidden = ['z'];

    public $timestamps = true;
}

#[WithoutTimestamps]
#[Fillable(['a'])]
#[Hidden(['h'])]
class GreasedInitParent extends Model
{
    use HasGreasedInitializers;
}
class GreasedInitChild extends GreasedInitParent
{
    protected $fillable = ['c'];

    protected $hidden = ['z'];

    public $timestamps = true;
}

trait AddsUserInitState
{
    public bool $userInitRan = false;

    public function initializeAddsUserInitState(): void
    {
        $this->userInitRan = true;
    }
}

#[Table(name: 'init_widgets', timestamps: false)]
#[Fillable(['name', 'qty'])]
#[Guarded(['secret'])]
#[Hidden(['secret'])]
#[Visible(['name', 'qty'])]
#[Touches(['owner'])]
class GreasedInitWithUserTrait extends Model
{
    use AddsUserInitState;
    use HasGreasedInitializers;
}
