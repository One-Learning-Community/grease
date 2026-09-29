<?php

/**
 * A/B: warm `resolveClassAttribute()` — vanilla's `"$class@$attr@$property"` concat key vs the
 * greased `[class][attr][property]` carve-out. Mirrors the booters' real call mix (a handful of
 * attributes, mostly with a property, mostly absent → null).
 *
 *   php benchmarks/class_attribute_ab.php [iterations]
 */

require __DIR__.'/../vendor/autoload.php';

use Grease\Concerns\HasGreasedClassAttributes;
use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Visible;
use Illuminate\Database\Eloquent\Model;

#[Table(name: 'ab_widgets')]
class AbVanillaCA extends Model {}

#[Table(name: 'ab_widgets')]
class AbGreasedCA extends Model
{
    use HasGreasedClassAttributes;
}

$calls = [
    [Table::class, 'name'], [Table::class, null], [Fillable::class, 'columns'], [Guarded::class, 'columns'],
    [Hidden::class, 'columns'], [Visible::class, 'columns'], [Appends::class, 'columns'], [Table::class, 'timestamps'],
];

$n = (int) ($argv[1] ?? 400_000);

$run = function (string $class) use ($calls, $n): float {
    $fn = Closure::bind(fn ($a, $p) => static::resolveClassAttribute($a, $p), null, $class);
    foreach ($calls as [$a, $p]) {
        $fn($a, $p); // warm
    }
    $t = hrtime(true);
    for ($i = 0; $i < $n; $i++) {
        foreach ($calls as [$a, $p]) {
            $fn($a, $p);
        }
    }

    return (hrtime(true) - $t) / ($n * count($calls));
};

for ($r = 0; $r < 5; $r++) {
    $v = $run(AbVanillaCA::class);
    $g = $run(AbGreasedCA::class);
    printf("vanilla %.1fns  greased %.1fns  %+.1f%%\n", $v, $g, ($g / $v - 1) * 100);
}
