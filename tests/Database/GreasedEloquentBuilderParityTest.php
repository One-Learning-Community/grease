<?php

namespace Grease\Tests\Database;

use BadMethodCallException;
use Grease\Concerns\HasGrease;
use Grease\Concerns\HasGreasedQueries;
use Grease\Database\Eloquent\Builder;
use Grease\Tests\TestCase;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Builder as BaseBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

trait QbScopes
{
    public function scopeActive($query)
    {
        return $query->where('active', 1);
    }
}

class VanillaQb extends Model
{
    use QbScopes;

    public $timestamps = false;

    protected $table = 'qb_rows';

    protected $guarded = [];
}

class GreasedQb extends Model
{
    use HasGrease;
    use QbScopes;

    public $timestamps = false;

    protected $table = 'qb_rows';

    protected $guarded = [];
}

class QbCustomBuilder extends BaseBuilder {}

#[UseEloquentBuilder(QbCustomBuilder::class)]
class GreasedQbWithCustomBuilder extends Model
{
    use HasGrease;

    public $timestamps = false;

    protected $table = 'qb_rows';

    protected $guarded = [];
}

/** A model whose scope name collides with a passthru ('count') and a #[Scope]-attribute scope. */
trait QbCollidingScopes
{
    public function scopeCount($query)
    {
        return $query->where('active', 1);
    }

    #[Scope]
    protected function highScore($query)
    {
        return $query->where('score', '>', 15);
    }
}

class VanillaScopeShadow extends Model
{
    use QbCollidingScopes;

    public $timestamps = false;

    protected $table = 'qb_rows';

    protected $guarded = [];
}

class GreasedScopeShadow extends Model
{
    use HasGrease;
    use QbCollidingScopes;

    public $timestamps = false;

    protected $table = 'qb_rows';

    protected $guarded = [];
}

// STI pairs — parent + child with a child-only scope; the verdict memo is keyed per concrete class.
class VanillaStiParent extends Model
{
    public $timestamps = false;

    protected $table = 'qb_rows';

    protected $guarded = [];

    public function scopeParentScope($query)
    {
        return $query->where('active', 1);
    }
}

class VanillaStiChild extends VanillaStiParent
{
    public function scopeChildOnly($query)
    {
        return $query->where('score', '>', 5);
    }
}

class GreasedStiParent extends Model
{
    use HasGrease;

    public $timestamps = false;

    protected $table = 'qb_rows';

    protected $guarded = [];

    public function scopeParentScope($query)
    {
        return $query->where('active', 1);
    }
}

class GreasedStiChild extends GreasedStiParent
{
    public function scopeChildOnly($query)
    {
        return $query->where('score', '>', 5);
    }
}

/**
 * {@see HasGreasedQueries} swaps the default Eloquent builder for a
 * {@see Builder} that memoizes the per-(model,method)
 * dispatch verdict in `__call` — the `hasNamedScope` probe and the 32-element
 * `in_array(strtolower(...))` passthru scan that vanilla recomputes on every
 * `where`/`orderBy`/`count`/etc. call. Contract: behaviour-identical dispatch.
 *
 * The two mutable arms stay live: local + global macros are re-probed first on
 * every call (a memoized verdict can never shadow a late-registered macro), and a
 * custom builder (the `#[UseEloquentBuilder]` attribute or a `static::$builder`
 * override) is honoured untouched — only the default builder is greased.
 */
class GreasedEloquentBuilderParityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('qb_rows');
        Schema::create('qb_rows', function (Blueprint $b) {
            $b->increments('id');
            $b->string('name');
            $b->integer('active')->default(0);
            $b->integer('score')->default(0);
        });

        DB::table('qb_rows')->insert([
            ['name' => 'a', 'active' => 1, 'score' => 30],
            ['name' => 'b', 'active' => 0, 'score' => 10],
            ['name' => 'c', 'active' => 1, 'score' => 20],
        ]);
    }

    public function test_greased_model_uses_the_greased_builder(): void
    {
        $this->assertSame('Grease\Database\Eloquent\Builder', get_class(GreasedQb::query()));
        $this->assertInstanceOf(BaseBuilder::class, GreasedQb::query());
    }

    public function test_forward_scope_and_passthru_results_match_vanilla(): void
    {
        // forward path (where/orderBy chain)
        $this->assertSame(
            VanillaQb::where('score', '>', 5)->orderBy('score', 'desc')->pluck('name')->all(),
            GreasedQb::where('score', '>', 5)->orderBy('score', 'desc')->pluck('name')->all(),
        );

        // named-scope path
        $this->assertSame(
            VanillaQb::active()->orderBy('id')->pluck('name')->all(),
            GreasedQb::active()->orderBy('id')->pluck('name')->all(),
        );

        // passthru path (terminal value-producers)
        $this->assertSame(VanillaQb::count(), GreasedQb::count());
        $this->assertSame(VanillaQb::where('active', 1)->exists(), GreasedQb::where('active', 1)->exists());
        $this->assertSame(VanillaQb::max('score'), GreasedQb::max('score'));
        $this->assertSame(VanillaQb::toSql(), GreasedQb::toSql());
    }

    public function test_call_verdicts_are_memoized(): void
    {
        // orderBy genuinely forwards via __call (where/orWhere are defined on Eloquent\Builder
        // and bypass it, so the memo applies to the other forwarded verbs, not where()).
        GreasedQb::query()->orderBy('score')->get();          // forward
        GreasedQb::active()->get();                            // scope
        GreasedQb::count();                                   // passthru

        $memo = new \ReflectionProperty('Grease\Database\Eloquent\Builder', 'greaseCallVerdicts');
        $verdicts = $memo->getValue()[GreasedQb::class] ?? [];

        $this->assertSame('forward', $verdicts['orderBy'] ?? null);
        $this->assertSame('scope', $verdicts['active'] ?? null);
        $this->assertSame('passthru', $verdicts['count'] ?? null);
    }

    public function test_macros_fire_including_registration_after_first_call(): void
    {
        // A forward verdict for 'where' is memoized first…
        GreasedQb::where('id', '>', 0)->get();

        // …then a global macro is registered; it must still fire (macros re-probed live).
        BaseBuilder::macro('greasedScoreSum', function () {
            return $this->sum('score');
        });

        try {
            $this->assertSame(VanillaQb::query()->greasedScoreSum(), GreasedQb::query()->greasedScoreSum());
            $this->assertSame(60, GreasedQb::query()->greasedScoreSum());
        } finally {
            // Eloquent\Builder has its own macro store (not the Macroable trait) — reset it
            // directly so the global macro doesn't leak into other tests.
            (new \ReflectionProperty(BaseBuilder::class, 'macros'))->setValue(null, []);
        }
    }

    public function test_undefined_method_throws_like_vanilla(): void
    {
        $this->expectException(BadMethodCallException::class);
        GreasedQb::query()->thisMethodDoesNotExistAnywhere();
    }

    public function test_custom_builder_attribute_is_honored_not_greased(): void
    {
        // A model that opts into a custom builder keeps it — the greased default never substitutes it.
        $this->assertInstanceOf(QbCustomBuilder::class, GreasedQbWithCustomBuilder::query());
        $this->assertNotInstanceOf('Grease\Database\Eloquent\Builder', GreasedQbWithCustomBuilder::query());
    }

    public function test_standalone_tier_swaps_builder_without_full_umbrella(): void
    {
        $model = new class extends Model
        {
            use HasGreasedQueries;

            protected $table = 'qb_rows';
        };

        $this->assertSame('Grease\Database\Eloquent\Builder', get_class($model->newQuery()));
    }

    // ── Adversarial-audit regression guards (verified clean 2026-06-25) ──────────────────

    public function test_named_scope_shadows_a_passthru_name_like_vanilla(): void
    {
        // scopeCount collides with the `count` passthru. Vanilla checks scope BEFORE passthru,
        // so ->count() applies the scope and returns a builder, NOT an aggregate int — the memo
        // must preserve that precedence.
        $vanilla = VanillaScopeShadow::count();
        $greased = GreasedScopeShadow::count();

        $this->assertInstanceOf(BaseBuilder::class, $greased, 'scope must win over the count passthru');
        $this->assertSame($vanilla->toSql(), $greased->toSql());
        $this->assertStringContainsString('active', $greased->toSql(), 'sanity: the scope, not an aggregate');
    }

    public function test_scope_attribute_method_resolves_like_vanilla(): void
    {
        $this->assertSame(
            VanillaScopeShadow::highScore()->orderBy('id')->pluck('name')->all(),
            GreasedScopeShadow::highScore()->orderBy('id')->pluck('name')->all(),
        );
    }

    public function test_sti_scope_verdict_does_not_leak_between_parent_and_child(): void
    {
        // The child's child-only scope resolves identically to vanilla…
        $this->assertSame(
            VanillaStiChild::childOnly()->orderBy('id')->pluck('name')->all(),
            GreasedStiChild::childOnly()->orderBy('id')->pluck('name')->all(),
        );

        // …and the parent — same method name, different concrete class — must NOT inherit the
        // child-keyed verdict: it has no childOnly scope, so it forwards and throws, on both arms.
        $vanillaThrew = false;
        try {
            VanillaStiParent::query()->childOnly();
        } catch (BadMethodCallException) {
            $vanillaThrew = true;
        }

        $greasedThrew = false;
        try {
            GreasedStiParent::query()->childOnly();
        } catch (BadMethodCallException) {
            $greasedThrew = true;
        }

        $this->assertTrue($vanillaThrew, 'sanity: vanilla parent has no childOnly');
        $this->assertSame($vanillaThrew, $greasedThrew, 'parent must not see the child-keyed verdict');
    }

    public function test_local_macro_is_not_shadowed_by_a_memoized_verdict(): void
    {
        $q = GreasedScopeShadow::query();
        $q->sum('score'); // memoize the 'sum' verdict as passthru in the static map first

        // A local macro registered AFTER must still win — macros are re-probed live before the memo.
        $q->macro('sum', fn ($self) => 'macroed');
        $this->assertSame('macroed', $q->sum(), 'local macro must shadow the memoized passthru verdict');
    }
}
