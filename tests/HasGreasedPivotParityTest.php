<?php

namespace Grease\Tests;

use Grease\Concerns\HasGrease;
use Grease\Concerns\HasGreasedPivots;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Concerns\InteractsWithPivotTable;
use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A custom `using` pivot — exercises the defer path: a relation with an explicit
 * `using(...)` must keep building THAT class, never the greased default pivot.
 */
class PivotParityCustomPivot extends Pivot
{
    protected function casts(): array
    {
        return ['rank' => 'integer'];
    }
}

// ── Vanilla side ─────────────────────────────────────────────────────────────
class VanillaPivotUser extends Model
{
    public $timestamps = false;

    protected $table = 'pp_users';

    protected $guarded = [];

    public function roles()
    {
        return $this->belongsToMany(VanillaPivotRole::class, 'pp_role_user', 'user_id', 'role_id')
            ->withPivot('level')->withTimestamps();
    }

    public function tags()
    {
        return $this->belongsToMany(VanillaPivotTag::class, 'pp_tag_user', 'user_id', 'tag_id')
            ->withPivot('weight');
    }

    public function teams()
    {
        return $this->belongsToMany(VanillaPivotTeam::class, 'pp_team_user', 'user_id', 'team_id')
            ->using(PivotParityCustomPivot::class)->withPivot('rank');
    }
}

class VanillaPivotRole extends Model
{
    public $timestamps = false;

    protected $table = 'pp_roles';

    protected $guarded = [];
}

class VanillaPivotTag extends Model
{
    public $timestamps = false;

    protected $table = 'pp_tags';

    protected $guarded = [];
}

class VanillaPivotTeam extends Model
{
    public $timestamps = false;

    protected $table = 'pp_teams';

    protected $guarded = [];
}

// ── Greased side (identical schema, models opt into HasGrease) ────────────────
class GreasedPivotUser extends Model
{
    use HasGrease;

    public $timestamps = false;

    protected $table = 'pp_users';

    protected $guarded = [];

    public function roles()
    {
        return $this->belongsToMany(GreasedPivotRole::class, 'pp_role_user', 'user_id', 'role_id')
            ->withPivot('level')->withTimestamps();
    }

    public function tags()
    {
        return $this->belongsToMany(GreasedPivotTag::class, 'pp_tag_user', 'user_id', 'tag_id')
            ->withPivot('weight');
    }

    public function teams()
    {
        return $this->belongsToMany(GreasedPivotTeam::class, 'pp_team_user', 'user_id', 'team_id')
            ->using(PivotParityCustomPivot::class)->withPivot('rank');
    }
}

class GreasedPivotRole extends Model
{
    use HasGrease;

    public $timestamps = false;

    protected $table = 'pp_roles';

    protected $guarded = [];
}

class GreasedPivotTag extends Model
{
    use HasGrease;

    public $timestamps = false;

    protected $table = 'pp_tags';

    protected $guarded = [];
}

class GreasedPivotTeam extends Model
{
    use HasGrease;

    public $timestamps = false;

    protected $table = 'pp_teams';

    protected $guarded = [];
}

// ── Morph pair: morphToMany builds a MorphPivot on the RELATION, bypassing the model seam ──
class VanillaPivotPost extends Model
{
    public $timestamps = false;

    protected $table = 'pp_posts';

    protected $guarded = [];

    public function tags()
    {
        return $this->morphToMany(VanillaPivotMorphTag::class, 'taggable', 'pp_taggables', 'taggable_id', 'tag_id')
            ->withPivot('note')->withTimestamps();
    }
}

class GreasedPivotPost extends Model
{
    use HasGrease;

    public $timestamps = false;

    protected $table = 'pp_posts';

    protected $guarded = [];

    public function tags()
    {
        return $this->morphToMany(GreasedPivotMorphTag::class, 'taggable', 'pp_taggables', 'taggable_id', 'tag_id')
            ->withPivot('note')->withTimestamps();
    }
}

class VanillaPivotMorphTag extends Model
{
    public $timestamps = false;

    protected $table = 'pp_mtags';

    protected $guarded = [];
}

class GreasedPivotMorphTag extends Model
{
    use HasGrease;

    public $timestamps = false;

    protected $table = 'pp_mtags';

    protected $guarded = [];
}

/**
 * The pivot of a many-to-many is a "dynamic model" the framework builds internally
 * ({@see InteractsWithPivotTable::newPivot}),
 * so it never carries Grease's tiers — every pivot row pays the per-row taxes
 * (resolveClassAttribute, the initialize* booters, the timestamp Carbon round-trip)
 * the model tiers exist to remove. {@see HasGreasedPivots} overrides
 * the related model's `newPivot()` so default pivots hydrate as a greased subclass.
 *
 * The contract: byte-for-byte identical to a vanilla pivot. A custom `using(...)`
 * pivot defers to vanilla unchanged (the encrypted-cast precedent), and so does
 * MorphToMany (it builds MorphPivot on the relation, bypassing the model seam).
 */
class HasGreasedPivotParityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach (['pp_users', 'pp_roles', 'pp_tags', 'pp_teams'] as $t) {
            Schema::dropIfExists($t);
            Schema::create($t, function (Blueprint $b) {
                $b->increments('id');
                $b->string('name')->nullable();
            });
        }

        Schema::dropIfExists('pp_role_user');
        Schema::create('pp_role_user', function (Blueprint $b) {
            $b->integer('user_id');
            $b->integer('role_id');
            $b->integer('level')->nullable();
            $b->timestamps();
        });

        Schema::dropIfExists('pp_tag_user');
        Schema::create('pp_tag_user', function (Blueprint $b) {
            $b->integer('user_id');
            $b->integer('tag_id');
            $b->integer('weight')->nullable();
        });

        Schema::dropIfExists('pp_team_user');
        Schema::create('pp_team_user', function (Blueprint $b) {
            $b->integer('user_id');
            $b->integer('team_id');
            $b->integer('rank')->nullable();
        });

        foreach (['pp_posts', 'pp_mtags'] as $t) {
            Schema::dropIfExists($t);
            Schema::create($t, function (Blueprint $b) {
                $b->increments('id');
                $b->string('name')->nullable();
            });
        }

        Schema::dropIfExists('pp_taggables');
        Schema::create('pp_taggables', function (Blueprint $b) {
            $b->integer('tag_id');
            $b->integer('taggable_id');
            $b->string('taggable_type');
            $b->string('note')->nullable();
            $b->timestamps();
        });

        foreach (['pp_users', 'pp_roles', 'pp_tags', 'pp_teams', 'pp_posts', 'pp_mtags'] as $t) {
            DB::table($t)->insert([['id' => 1, 'name' => 'a'], ['id' => 2, 'name' => 'b']]);
        }

        // Morph pivots for both post classes (the stored morph type is the concrete post class).
        foreach ([VanillaPivotPost::class, GreasedPivotPost::class] as $type) {
            DB::table('pp_taggables')->insert([
                ['tag_id' => 1, 'taggable_id' => 1, 'taggable_type' => $type, 'note' => 'n1', 'created_at' => '2026-03-04 05:06:07', 'updated_at' => '2026-03-04 05:06:07'],
                ['tag_id' => 2, 'taggable_id' => 1, 'taggable_type' => $type, 'note' => 'n2', 'created_at' => '2026-04-05 06:07:08', 'updated_at' => '2026-04-05 06:07:08'],
            ]);
        }

        // Fixed timestamps so the pivot's Carbon serialize round-trip is deterministic.
        DB::table('pp_role_user')->insert([
            ['user_id' => 1, 'role_id' => 1, 'level' => 5, 'created_at' => '2026-01-02 03:04:05', 'updated_at' => '2026-01-02 03:04:05'],
            ['user_id' => 1, 'role_id' => 2, 'level' => 9, 'created_at' => '2026-02-03 04:05:06', 'updated_at' => '2026-02-03 04:05:06'],
        ]);
        DB::table('pp_tag_user')->insert([
            ['user_id' => 1, 'tag_id' => 1, 'weight' => 11],
            ['user_id' => 1, 'tag_id' => 2, 'weight' => 22],
        ]);
        DB::table('pp_team_user')->insert([
            ['user_id' => 1, 'team_id' => 1, 'rank' => 7],
        ]);
    }

    private function assertPivotParity(Pivot $vanilla, Pivot $greased, string $context): void
    {
        $this->assertSame($vanilla->toArray(), $greased->toArray(), "$context: toArray");
        $this->assertSame($vanilla->getAttributes(), $greased->getAttributes(), "$context: getAttributes");
        // getRawOriginal (stored strings), NOT getOriginal — the latter casts dates to fresh
        // Carbon instances that assertSame compares by object identity (fails vanilla-vs-vanilla too).
        $this->assertSame($vanilla->getRawOriginal(), $greased->getRawOriginal(), "$context: getRawOriginal");
        $this->assertSame($vanilla->getTable(), $greased->getTable(), "$context: table");
    }

    public function test_belongs_to_many_pivot_is_byte_identical_and_greased(): void
    {
        $vUser = VanillaPivotUser::with('roles')->find(1);
        $gUser = GreasedPivotUser::with('roles')->find(1);

        $vRoles = $vUser->roles->sortBy('id')->values();
        $gRoles = $gUser->roles->sortBy('id')->values();

        $this->assertCount(2, $gRoles);

        foreach ([0, 1] as $i) {
            $vPivot = $vRoles[$i]->pivot;
            $gPivot = $gRoles[$i]->pivot;

            // The optimization is active: the default pivot hydrates as the greased subclass…
            $this->assertSame('Grease\Eloquent\Pivot', get_class($gPivot), 'greased pivot class');
            // …while staying a real Pivot, so nothing downstream that type-checks it breaks.
            $this->assertInstanceOf(Pivot::class, $gPivot);

            // …and its output is byte-identical to vanilla (incl. the timestamp round-trip).
            $this->assertPivotParity($vPivot, $gPivot, "role pivot #$i");
            $this->assertSame($vPivot->level, $gPivot->level, "role pivot #$i: level accessor");
            $this->assertSame((string) $vPivot->created_at, (string) $gPivot->created_at, "role pivot #$i: created_at");
        }
    }

    public function test_two_pivot_tables_on_one_greased_class_keep_distinct_tables(): void
    {
        // Same greased Pivot class, two different relations/tables — the per-class blueprint
        // must NOT bleed the first relation's table/state into the second (dynamic-model risk).
        $gUser = GreasedPivotUser::with(['roles', 'tags'])->find(1);
        $vUser = VanillaPivotUser::with(['roles', 'tags'])->find(1);

        $gRolePivot = $gUser->roles->firstWhere('id', 1)->pivot;
        $gTagPivot = $gUser->tags->firstWhere('id', 1)->pivot;

        $this->assertSame('pp_role_user', $gRolePivot->getTable());
        $this->assertSame('pp_tag_user', $gTagPivot->getTable());
        $this->assertSame('Grease\Eloquent\Pivot', get_class($gRolePivot));
        $this->assertSame('Grease\Eloquent\Pivot', get_class($gTagPivot));

        $this->assertPivotParity($vUser->roles->firstWhere('id', 1)->pivot, $gRolePivot, 'role pivot');
        $this->assertPivotParity($vUser->tags->firstWhere('id', 1)->pivot, $gTagPivot, 'tag pivot (no timestamps)');
    }

    public function test_custom_using_pivot_defers_to_vanilla_unchanged(): void
    {
        $vUser = VanillaPivotUser::with('teams')->find(1);
        $gUser = GreasedPivotUser::with('teams')->find(1);

        $vPivot = $vUser->teams->first()->pivot;
        $gPivot = $gUser->teams->first()->pivot;

        // The explicit using() class wins on BOTH sides — never substituted by the greased default.
        $this->assertInstanceOf(PivotParityCustomPivot::class, $gPivot);
        $this->assertSame(get_class($vPivot), get_class($gPivot));
        $this->assertPivotParity($vPivot, $gPivot, 'custom using pivot');
        $this->assertSame(7, $gPivot->rank);
    }

    // ── Adversarial-audit regression guards (verified clean 2026-06-25) ──────────────────

    public function test_morph_to_many_pivot_stays_vanilla_morph_pivot(): void
    {
        // MorphToMany overrides newPivot() on the RELATION (builds MorphPivot directly), bypassing
        // the model's newPivot seam — so morph pivots are NOT greased. Correct, just unaccelerated,
        // and must be byte-identical to vanilla's MorphPivot.
        $vPost = VanillaPivotPost::with('tags')->find(1);
        $gPost = GreasedPivotPost::with('tags')->find(1);

        $vPivot = $vPost->tags->sortBy('id')->first()->pivot;
        $gPivot = $gPost->tags->sortBy('id')->first()->pivot;

        $this->assertSame(MorphPivot::class, get_class($gPivot), 'morph pivot must stay vanilla MorphPivot');
        $this->assertNotSame('Grease\Eloquent\Pivot', get_class($gPivot), 'the model seam must NOT intercept morph');

        // taggable_type is the concrete post class, which legitimately differs between the two
        // fixture classes — normalize it; every other field must be byte-identical.
        $strip = function (array $a): array {
            unset($a['taggable_type']);

            return $a;
        };
        $this->assertSame($strip($vPivot->toArray()), $strip($gPivot->toArray()));
        $this->assertSame($strip($vPivot->getRawOriginal()), $strip($gPivot->getRawOriginal()));
        $this->assertSame(VanillaPivotPost::class, $vPivot->taggable_type);
        $this->assertSame(GreasedPivotPost::class, $gPivot->taggable_type);
    }

    public function test_pivot_write_path_is_byte_identical(): void
    {
        // The greased pivot's WRITE path (its timestamp serialization on attach/update) must store
        // exactly what vanilla stores. Freeze time so now()-derived timestamps are deterministic.
        Carbon::setTestNow('2026-07-08 09:10:11');

        try {
            DB::table('pp_role_user')->where('user_id', 2)->delete();
            VanillaPivotUser::query()->find(2)->roles()->attach(1, ['level' => 4]);
            $vAttach = (array) DB::table('pp_role_user')->where('user_id', 2)->where('role_id', 1)->first();

            DB::table('pp_role_user')->where('user_id', 2)->delete();
            GreasedPivotUser::query()->find(2)->roles()->attach(1, ['level' => 4]);
            $gAttach = (array) DB::table('pp_role_user')->where('user_id', 2)->where('role_id', 1)->first();

            $this->assertSame($vAttach, $gAttach, 'attach() must write an identical pivot row (incl. timestamps)');

            // And updateExistingPivot — the touched updated_at must match vanilla byte-for-byte.
            Carbon::setTestNow('2026-08-09 10:11:12');

            VanillaPivotUser::query()->find(2)->roles()->updateExistingPivot(1, ['level' => 8]);
            $vUpd = (array) DB::table('pp_role_user')->where('user_id', 2)->where('role_id', 1)->first();

            GreasedPivotUser::query()->find(2)->roles()->updateExistingPivot(1, ['level' => 8]);
            $gUpd = (array) DB::table('pp_role_user')->where('user_id', 2)->where('role_id', 1)->first();

            $this->assertSame($vUpd, $gUpd, 'updateExistingPivot() must write an identical row');
        } finally {
            Carbon::setTestNow();
        }
    }
}
