<?php

namespace Grease\Tests;

use Grease\Concerns\HasGrease;
use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Attributes\Connection;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Refreshes;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Touches;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Attributes\Visible;
use Illuminate\Database\Eloquent\Attributes\WithoutIncrementing;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Concerns\AsPivot;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The drift net for every per-class snapshot the model tiers take.
 *
 * `HasGreasedHydration` and `HasGreasedInitializers` run each framework booter once per class
 * and replay a snapshot of the properties it wrote. That is only correct while the snapshot
 * lists *every* property the booter writes — and upstream keeps adding them (13.33's
 * `#[Refreshes]` → `$refreshes` slipped through for months). So instead of asserting the
 * properties we know about, this compares the model's **entire** instance state
 * (`get_object_vars()` from Model scope) against vanilla, for a model carrying every
 * instance-affecting Eloquent class attribute — cold, warm, and hydrated via `newFromBuilder`.
 *
 * {@see test_every_eloquent_attribute_class_is_accounted_for()} is the tripwire that keeps the
 * fixtures honest: a Laravel release that adds an attribute class fails it until the attribute
 * is on a fixture here (or listed as not instance-affecting).
 */
class HasGreaseWholeStateParityTest extends TestCase
{
    /**
     * Every class in `Illuminate\Database\Eloquent\Attributes`, and where it's covered.
     * Adding a new upstream attribute here without covering it defeats the point — put it on a
     * fixture below first if it can change what a model instance holds.
     */
    private const ACCOUNTED_FOR = [
        // On the whole-state fixtures below.
        'Appends' => 'fixture', 'Connection' => 'fixture', 'DateFormat' => 'fixture',
        'Fillable' => 'fixture', 'Guarded' => 'fixture', 'Hidden' => 'fixture',
        'Refreshes' => 'fixture', 'RouteKey' => 'fixture', 'Table' => 'fixture',
        'Touches' => 'fixture', 'Unguarded' => 'fixture', 'Visible' => 'fixture',
        'WithoutIncrementing' => 'fixture', 'WithoutTimestamps' => 'fixture',
        // Method-targeted, or class-level but resolved outside any greased booter (static
        // registration / builder / collection / factory / policy / resource resolution).
        'Boot' => 'method', 'Initialize' => 'method', 'Scope' => 'method',
        'CollectedBy' => 'not-instance-state', 'ObservedBy' => 'not-instance-state',
        'ScopedBy' => 'not-instance-state', 'UseEloquentBuilder' => 'not-instance-state',
        'UseFactory' => 'not-instance-state', 'UsePolicy' => 'not-instance-state',
        'UseResource' => 'not-instance-state', 'UseResourceCollection' => 'not-instance-state',
    ];

    public function test_every_eloquent_attribute_class_is_accounted_for(): void
    {
        $dir = dirname((new \ReflectionClass(Model::class))->getFileName()).'/Attributes';

        $upstream = array_map(fn ($f) => basename($f, '.php'), glob($dir.'/*.php'));
        sort($upstream);

        $unaccounted = array_values(array_diff($upstream, array_keys(self::ACCOUNTED_FOR)));

        $this->assertSame([], $unaccounted, 'Laravel added Eloquent attribute(s) '.implode(', ', $unaccounted)
            .': put each on a whole-state fixture (and check the greased booters snapshot what it writes), then list it in ACCOUNTED_FOR.');
    }

    public static function pairs(): array
    {
        return [
            'every attribute' => [VanillaWholeStateFull::class, GreasedWholeStateFull::class],
            'unguarded / no-increment / no-timestamps' => [VanillaWholeStateFlags::class, GreasedWholeStateFlags::class],
            'plain' => [VanillaWholeStatePlain::class, GreasedWholeStatePlain::class],
        ];
    }

    #[DataProvider('pairs')]
    public function test_cold_and_warm_instances_match_vanilla(string $vanilla, string $greased): void
    {
        $greased::flushGreaseBlueprint();

        $this->assertSame($this->state(new $vanilla), $this->state(new $greased), 'cold');
        $this->assertSame($this->state(new $vanilla), $this->state(new $greased), 'warm');
    }

    #[DataProvider('pairs')]
    public function test_hydrated_instances_match_vanilla(string $vanilla, string $greased): void
    {
        $row = ['id' => 7, 'name' => 'widget'];

        for ($i = 0; $i < 2; $i++) {
            $this->assertSame(
                $this->state((new $vanilla)->newFromBuilder($row, 'alt')),
                $this->state((new $greased)->newFromBuilder($row, 'alt')),
                $i === 0 ? 'first hydrate' : 'warm hydrate',
            );
        }
    }

    /**
     * The derived-table memo must only apply to vanilla `Model::getTable()`. An instance-dependent
     * override (sharding) is called on every hydration — each prototype hands on its own table.
     */
    public function test_instance_dependent_get_table_override_is_honored(): void
    {
        foreach ([2023, 2024, 2023] as $year) {
            $hydrate = function (string $class) use ($year) {
                $prototype = new $class;
                $prototype->year = $year;
                $row = $prototype->newFromBuilder(['id' => 1]);

                return [$this->state($row), $row->getTable()];
            };

            [$vanillaState, $vanillaTable] = $hydrate(VanillaShardedOrder::class);
            [$greasedState, $greasedTable] = $hydrate(GreasedShardedOrder::class);

            $this->assertSame("orders_$year", $vanillaState['table'], 'vanilla hands on the prototype table');
            $this->assertSame($vanillaState, $greasedState, "year $year");
            $this->assertSame($vanillaTable, $greasedTable);
        }
    }

    /**
     * HasGrease must compose with any other trait that defines getTable() (AsPivot, …) — this file
     * loading at all proves there's no trait-method collision — and hydrate the same state.
     */
    public function test_composes_with_a_trait_that_defines_get_table(): void
    {
        for ($i = 0; $i < 2; $i++) {
            $this->assertSame(
                $this->state((new VanillaAsPivotModel)->newFromBuilder(['id' => 1])),
                $this->state((new GreasedAsPivotModel)->newFromBuilder(['id' => 1])),
            );
        }
    }

    /**
     * Every instance property, read from Model scope, minus Grease's own bookkeeping. A derived
     * table name embeds the class name, so the Vanilla/Greased prefix is normalized away.
     */
    private function state(Model $model): array
    {
        $vars = (fn () => get_object_vars($this))->call($model);

        if (is_string($vars['table'])) {
            $vars['table'] = preg_replace('/^(vanilla|greased)_?/', '', $vars['table']);
        }

        return array_filter($vars, fn ($key) => ! str_starts_with($key, 'grease'), ARRAY_FILTER_USE_KEY);
    }
}

#[Table(name: 'whole_widgets', key: 'uuid', keyType: 'string', dateFormat: 'Y-m-d H:i')]
#[Connection('alt')]
#[DateFormat('U')]
#[Fillable(['name', 'qty'])]
#[Guarded(['secret'])]
#[Hidden(['secret'])]
#[Visible(['name', 'qty'])]
#[Appends(['label'])]
#[Touches(['owner'])]
#[Refreshes(['version', 'updated_by'])]
#[RouteKey('slug')]
class VanillaWholeStateFull extends Model {}

#[Table(name: 'whole_widgets', key: 'uuid', keyType: 'string', dateFormat: 'Y-m-d H:i')]
#[Connection('alt')]
#[DateFormat('U')]
#[Fillable(['name', 'qty'])]
#[Guarded(['secret'])]
#[Hidden(['secret'])]
#[Visible(['name', 'qty'])]
#[Appends(['label'])]
#[Touches(['owner'])]
#[Refreshes(['version', 'updated_by'])]
#[RouteKey('slug')]
class GreasedWholeStateFull extends Model
{
    use HasGrease;
}

#[Unguarded]
#[WithoutIncrementing]
#[WithoutTimestamps]
class VanillaWholeStateFlags extends Model {}

#[Unguarded]
#[WithoutIncrementing]
#[WithoutTimestamps]
class GreasedWholeStateFlags extends Model
{
    use HasGrease;
}

class VanillaWholeStatePlain extends Model {}

class GreasedWholeStatePlain extends Model
{
    use HasGrease;
}

class VanillaShardedOrder extends Model
{
    public int $year = 2020;

    public function getTable()
    {
        return 'orders_'.$this->year;
    }
}

class GreasedShardedOrder extends VanillaShardedOrder
{
    use HasGrease;
}

class VanillaAsPivotModel extends Model
{
    use AsPivot;
}

class GreasedAsPivotModel extends Model
{
    use AsPivot, HasGrease;
}
