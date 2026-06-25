<?php

namespace Grease\Bench;

use Grease\Bench\Support\BootsEloquent;
use Grease\Concerns\HasGreasedPivots;
use Grease\Tests\HasGreasedPivotParityTest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\RetryThreshold;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Warmup;

/**
 * In-memory A/B for the greased pivot. A many-to-many's pivot is hydrated per related
 * row and vanilla never carries Grease's tiers, so the pivot pays the full per-row
 * booter/resolveClassAttribute/timestamp-Carbon cost the model tiers remove.
 *
 * Both arms use a VANILLA related model; only the pivot class differs (the related model
 * with `HasGreasedPivots` returns a greased pivot), so the delta is the pivot's alone.
 * Parity of the two pivots is proven byte-identical by {@see HasGreasedPivotParityTest}.
 */
#[
    BeforeMethods('setUp'),
    Warmup(1),
    Revs(50),
    Iterations(5),
    RetryThreshold(3),
]
class PivotBench
{
    public function setUp(): void
    {
        $capsule = BootsEloquent::capsule();
        $schema = $capsule->schema();

        if ($schema->hasTable('pivbench_users')) {
            return;
        }

        $schema->create('pivbench_users', function (Blueprint $t) {
            $t->increments('id');
            $t->string('name');
        });
        $schema->create('pivbench_roles', function (Blueprint $t) {
            $t->increments('id');
            $t->string('name');
        });
        $schema->create('pivbench_role_user', function (Blueprint $t) {
            $t->integer('user_id');
            $t->integer('role_id');
            $t->integer('level')->nullable();
            $t->timestamps();
        });

        for ($u = 1; $u <= 10; $u++) {
            PivbenchUser::query()->insert(['id' => $u, 'name' => "u$u"]);
        }
        for ($r = 1; $r <= 10; $r++) {
            PivbenchRoleVanilla::query()->insert(['id' => $r, 'name' => "r$r"]);
        }
        $rows = [];
        for ($u = 1; $u <= 10; $u++) {
            for ($r = 1; $r <= 10; $r++) {
                $rows[] = [
                    'user_id' => $u, 'role_id' => $r, 'level' => ($u * $r) % 7,
                    'created_at' => '2026-01-02 03:04:05', 'updated_at' => '2026-02-03 04:05:06',
                ];
            }
        }
        $capsule->getConnection()->table('pivbench_role_user')->insert($rows);
    }

    public function benchPivotHydrateVanilla(): void
    {
        $this->hydrate('rolesVanilla');
    }

    public function benchPivotHydrateGreased(): void
    {
        $this->hydrate('rolesGreased');
    }

    private function hydrate(string $relation): void
    {
        foreach (PivbenchUser::with($relation)->get() as $user) {
            foreach ($user->{$relation} as $role) {
                $role->pivot->toArray();
            }
        }
    }
}

class PivbenchRoleVanilla extends Model
{
    public $timestamps = false;

    protected $table = 'pivbench_roles';

    protected $guarded = [];
}

class PivbenchRoleGreased extends Model
{
    use HasGreasedPivots;

    public $timestamps = false;

    protected $table = 'pivbench_roles';

    protected $guarded = [];
}

class PivbenchUser extends Model
{
    public $timestamps = false;

    protected $table = 'pivbench_users';

    protected $guarded = [];

    public function rolesVanilla()
    {
        return $this->belongsToMany(PivbenchRoleVanilla::class, 'pivbench_role_user', 'user_id', 'role_id')
            ->withPivot('level')->withTimestamps();
    }

    public function rolesGreased()
    {
        return $this->belongsToMany(PivbenchRoleGreased::class, 'pivbench_role_user', 'user_id', 'role_id')
            ->withPivot('level')->withTimestamps();
    }
}
