<?php

/**
 * Tier-isolated A/B for the greased pivot (HasGreasedPivots / Grease\Eloquent\Pivot).
 *
 * The pivot of a many-to-many is hydrated per related row and never carries Grease's
 * tiers, so a pivot-heavy `belongsToMany()->get()` pays — per pivot row — the booter /
 * resolveClassAttribute / timestamp-Carbon-round-trip cost the model tiers remove.
 *
 * To isolate the PIVOT (not the related model), BOTH arms use a vanilla related model;
 * only the pivot class differs:
 *   A = related model as-is              → vanilla Illuminate\…\Relations\Pivot
 *   B = related model + HasGreasedPivots → Grease\Eloquent\Pivot (carries HasGrease)
 *
 * Pivot output is parity-asserted byte-identical before timing.
 *
 *   php -d xdebug.mode=off -d opcache.jit=tracing -d memory_limit=1G benchmarks/pivot_ab.php [users] [rolesPerUser] [iters]
 */

require __DIR__.'/../vendor/autoload.php';

use Grease\Bench\Support\BootsEloquent;
use Grease\Concerns\HasGreasedPivots;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;

$nUsers = (int) ($argv[1] ?? 50);
$nRoles = (int) ($argv[2] ?? 20);
$iters = (int) ($argv[3] ?? 400);

$capsule = BootsEloquent::capsule();
$schema = $capsule->schema();

$schema->create('pab_users', fn (Blueprint $t) => tap($t, function ($t) {
    $t->increments('id');
    $t->string('name');
}));
$schema->create('pab_roles', fn (Blueprint $t) => tap($t, function ($t) {
    $t->increments('id');
    $t->string('name');
}));
$schema->create('pab_role_user', function (Blueprint $t) {
    $t->integer('user_id');
    $t->integer('role_id');
    $t->integer('level')->nullable();
    $t->timestamps();
});

// --- Vanilla related model → vanilla pivot ---
class PabRoleVanilla extends Model
{
    public $timestamps = false;

    protected $table = 'pab_roles';

    protected $guarded = [];
}

// --- Same model + ONLY the pivot tier → greased pivot ---
class PabRoleGreased extends Model
{
    use HasGreasedPivots;

    public $timestamps = false;

    protected $table = 'pab_roles';

    protected $guarded = [];
}

class PabUser extends Model
{
    public $timestamps = false;

    protected $table = 'pab_users';

    protected $guarded = [];

    public function rolesVanilla()
    {
        return $this->belongsToMany(PabRoleVanilla::class, 'pab_role_user', 'user_id', 'role_id')
            ->withPivot('level')->withTimestamps();
    }

    public function rolesGreased()
    {
        return $this->belongsToMany(PabRoleGreased::class, 'pab_role_user', 'user_id', 'role_id')
            ->withPivot('level')->withTimestamps();
    }
}

// --- Seed ---
for ($u = 1; $u <= $nUsers; $u++) {
    PabUser::query()->insert(['id' => $u, 'name' => "u$u"]);
}
for ($r = 1; $r <= $nRoles; $r++) {
    PabRoleVanilla::query()->insert(['id' => $r, 'name' => "r$r"]);
}
$pivots = [];
for ($u = 1; $u <= $nUsers; $u++) {
    for ($r = 1; $r <= $nRoles; $r++) {
        $pivots[] = [
            'user_id' => $u, 'role_id' => $r, 'level' => ($u * $r) % 10,
            'created_at' => '2026-01-02 03:04:05', 'updated_at' => '2026-02-03 04:05:06',
        ];
    }
}
foreach (array_chunk($pivots, 200) as $chunk) {
    $capsule->getConnection()->table('pab_role_user')->insert($chunk);
}

// --- Parity gate (must hold before timing) ---
$vUser = PabUser::with('rolesVanilla')->find(1);
$gUser = PabUser::with('rolesGreased')->find(1);
$vPivot = $vUser->rolesVanilla->first()->pivot;
$gPivot = $gUser->rolesGreased->first()->pivot;

if ($vPivot->toArray() !== $gPivot->toArray() || $vPivot->getRawOriginal() !== $gPivot->getRawOriginal()) {
    fwrite(STDERR, "PARITY FAILED — aborting\n");
    exit(1);
}
if (get_class($gPivot) !== 'Grease\Eloquent\Pivot') {
    fwrite(STDERR, 'greased arm did not produce a greased pivot ('.get_class($gPivot).")\n");
    exit(1);
}

// --- Timing ---
$time = function (string $relation) use ($iters): float {
    $t = hrtime(true);
    for ($i = 0; $i < $iters; $i++) {
        foreach (PabUser::with($relation)->get() as $user) {
            foreach ($user->{$relation} as $role) {
                $role->pivot->toArray();
            }
        }
    }

    return (hrtime(true) - $t) / $iters / 1000; // µs per full pass
};

$time('rolesVanilla'); // warm
$vanilla = $time('rolesVanilla');
$greased = $time('rolesGreased');

printf("\n=== pivot A/B (%d users × %d roles = %d pivot rows/pass, %d iters) ===\n\n",
    $nUsers, $nRoles, $nUsers * $nRoles, $iters);
printf("parity: IDENTICAL (toArray + rawOriginal), greased pivot = %s\n\n", get_class($gPivot));
printf("vanilla pivot  : %10.1f µs/pass\n", $vanilla);
printf("greased pivot  : %10.1f µs/pass\n", $greased);
printf("delta          : %+9.1f%%  (run on benchmarks/docker for trustworthy magnitudes)\n\n",
    ($greased - $vanilla) / $vanilla * 100);
