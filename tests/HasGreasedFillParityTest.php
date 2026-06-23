<?php

namespace Grease\Tests;

use Grease\Concerns\HasGreasedHydration;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Eloquent\Model;

/**
 * {@see HasGreasedHydration} short-circuits `fill([])` — the empty fill that `__construct`
 * runs on every `new` model (and so every hydrated row). The contract: `fill([])` is a pure
 * no-op returning `$this`, so returning early is byte-identical, while any *non-empty* fill
 * defers to vanilla untouched (fillable filtering, guarded enforcement, the mass-assignment
 * throw, the silent-discard guard). Vanilla is the oracle: identical models, identical calls,
 * identical resulting attributes / exceptions.
 */
class HasGreasedFillParityTest extends TestCase
{
    protected function tearDown(): void
    {
        Model::preventSilentlyDiscardingAttributes(false);

        parent::tearDown();
    }

    public function test_empty_fill_is_a_noop_returning_self_like_vanilla(): void
    {
        $vanilla = new VanillaFillFixture;
        $greased = new GreasedFillFixture;

        $this->assertSame($greased, $greased->fill([]), 'fill([]) returns $this');
        $this->assertSame($vanilla->getAttributes(), $greased->getAttributes());
        $this->assertSame([], $greased->getAttributes(), 'fill([]) sets nothing');
    }

    public function test_empty_fill_does_not_throw_under_prevent_silent_discarding(): void
    {
        // The discard guard hinges on count([]) !== count([]) (false), so empty never throws —
        // the short-circuit must preserve that even with the global flag on.
        Model::preventSilentlyDiscardingAttributes(true);

        $vanilla = (new VanillaFillFixture)->fill([]);
        $greased = (new GreasedFillFixture)->fill([]);

        $this->assertSame($vanilla->getAttributes(), $greased->getAttributes());
    }

    public function test_non_empty_fill_respects_fillable_like_vanilla(): void
    {
        $attrs = ['name' => 'a', 'qty' => 5, 'secret' => 'nope'];

        $vanilla = (new VanillaFillFixture)->fill($attrs);
        $greased = (new GreasedFillFixture)->fill($attrs);

        $this->assertSame($vanilla->getAttributes(), $greased->getAttributes());
        $this->assertArrayNotHasKey('secret', $greased->getAttributes(), 'non-fillable filtered');
    }

    public function test_totally_guarded_non_empty_fill_throws_like_vanilla(): void
    {
        // A model with empty fillable and guarded ['*'] is totallyGuarded → any unknown key
        // throws. The short-circuit only touches the empty case, so this must still throw.
        $vanillaThrew = false;
        try {
            (new VanillaGuardedFixture)->fill(['x' => 1]);
        } catch (MassAssignmentException) {
            $vanillaThrew = true;
        }

        $greasedThrew = false;
        try {
            (new GreasedGuardedFixture)->fill(['x' => 1]);
        } catch (MassAssignmentException) {
            $greasedThrew = true;
        }

        $this->assertTrue($vanillaThrew, 'sanity: vanilla throws');
        $this->assertSame($vanillaThrew, $greasedThrew, 'greased matches vanilla');
    }
}

// --- Fixtures: identical fillable/guarded on a vanilla (oracle) and a greased class. --------

class VanillaFillFixture extends Model
{
    protected $table = 'fill_widgets';
    protected $fillable = ['name', 'qty'];
}

class GreasedFillFixture extends Model
{
    use HasGreasedHydration;

    protected $table = 'fill_widgets';
    protected $fillable = ['name', 'qty'];
}

class VanillaGuardedFixture extends Model
{
    protected $table = 'fill_widgets';
    protected $guarded = ['*'];
}

class GreasedGuardedFixture extends Model
{
    use HasGreasedHydration;

    protected $table = 'fill_widgets';
    protected $guarded = ['*'];
}
