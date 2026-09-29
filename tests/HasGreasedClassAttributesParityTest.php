<?php

namespace Grease\Tests;

use Grease\Concerns\HasGreasedClassAttributes;
use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Attributes\Connection;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Touches;
use Illuminate\Database\Eloquent\Attributes\Visible;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use ReflectionMethod;

/**
 * {@see HasGreasedClassAttributes} overrides `Model::resolveClassAttribute()` with a
 * concat-free, blueprint-backed cache. The contract is byte-for-byte identical resolution:
 * for every (attribute, property) the greased method must return exactly what vanilla's
 * does — present or absent, with a property or without, inherited up the parent chain, and
 * including vanilla's property-less cache-key quirk. Vanilla's own method is the oracle:
 * each case is resolved on a vanilla class and a greased class with identical attributes and
 * the results compared.
 */
class HasGreasedClassAttributesParityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // resolveClassAttribute() and the #[Table]/#[Fillable]/… attributes it reads are a
        // newer-Laravel feature; on a framework without it the tier is inert (the override is
        // never reached) and vanilla has no method to compare against. Skip there.
        if (! method_exists(Model::class, 'resolveClassAttribute')) {
            $this->markTestSkipped('Model PHP attributes require a newer Laravel; this tier is inert here.');
        }
    }

    /** Distinct attributes (no key collision) — cold path, property extraction, and absent. */
    public function test_resolve_matches_vanilla_across_a_battery(): void
    {
        // Distinct attribute keys only (the property-less-key collision is its own test
        // below), so neither side is flushed — both accumulate the cache identically.
        $battery = [
            [Table::class, null],              // object return, no property
            [Fillable::class, 'columns'],      // property extraction
            [Hidden::class, 'columns'],
            [Visible::class, 'columns'],
            [Appends::class, 'columns'],
            [Connection::class, 'name'],
            [DateFormat::class, 'format'],
            [Touches::class, 'relations'],
            [WithoutTimestamps::class, null],  // absent on these models → null
        ];

        foreach ($battery as [$attr, $property]) {
            $vanilla = $this->norm($this->resolve(VanillaCAFixture::class, $attr, $property));
            $greased = $this->norm($this->resolve(GreasedCAFixture::class, $attr, $property));

            $this->assertSame($vanilla, $greased, "resolve($attr, ".var_export($property, true).')');
        }
    }

    public function test_absent_attribute_caches_null_and_matches_vanilla(): void
    {
        // The common case: no attribute present → null, and (the point of the tier) the null
        // is cached, not re-resolved. Two calls, identical to vanilla both times.
        for ($i = 0; $i < 2; $i++) {
            $this->assertSame(
                $this->resolve(VanillaPlainFixture::class, Fillable::class, 'columns'),
                $this->resolve(GreasedPlainFixture::class, Fillable::class, 'columns'),
            );
        }
    }

    public function test_inherited_attribute_is_walked_up_the_parent_chain(): void
    {
        // The attribute sits on the parent; resolving on the child must walk to it — exactly
        // as vanilla's do/while over getParentClass() does.
        $this->assertSame(
            $this->norm($this->resolve(VanillaChildFixture::class, Table::class, 'name')),
            $this->norm($this->resolve(GreasedChildFixture::class, Table::class, 'name')),
        );
    }

    /**
     * Vanilla keys the cache by class+attribute+property (laravel/framework#60815), so the same
     * attribute resolved once with a property and once without yields two independent answers,
     * in either order. (Before 13.21 the key omitted the property and the second call returned
     * whatever the first cached — a quirk Grease once reproduced; the floor now excludes it.)
     */
    public function test_property_is_part_of_the_cache_key_in_either_order(): void
    {
        // Order A: no-property first, then the property → the instance, then the bool.
        $vA = $this->norm($this->secondOf(VanillaColAFixture::class, [Table::class, null], [Table::class, 'timestamps']));
        $gA = $this->norm($this->secondOf(GreasedColAFixture::class, [Table::class, null], [Table::class, 'timestamps'], fresh: true));
        $this->assertSame($vA, $gA);
        $this->assertFalse($gA, 'order A second call should return the timestamps bool');

        // Order B: property first, then no property → the bool, then the instance.
        $vB = $this->norm($this->secondOf(VanillaColBFixture::class, [Table::class, 'timestamps'], [Table::class, null]));
        $gB = $this->norm($this->secondOf(GreasedColBFixture::class, [Table::class, 'timestamps'], [Table::class, null], fresh: true));
        $this->assertSame($vB, $gB);
        $this->assertIsArray($gB, 'order B second call should return the Table instance');
    }

    /**
     * Vanilla also reads attributes declared on a *trait* the class (or a parent) uses
     * (laravel/framework#60566) — the class's own attribute wins, then its traits, then the
     * parent's. Every shape resolved on both sides and compared.
     */
    public function test_attributes_declared_on_traits_are_resolved(): void
    {
        $cases = [
            [VanillaTraitFixture::class, GreasedTraitFixture::class],           // trait only
            [VanillaTraitChildFixture::class, GreasedTraitChildFixture::class], // trait on parent
            [VanillaTraitOwnFixture::class, GreasedTraitOwnFixture::class],     // own beats trait
        ];

        foreach ($cases as [$vanilla, $greased]) {
            foreach ([[Table::class, 'name'], [Fillable::class, 'columns'], [Table::class, null]] as [$attr, $property]) {
                $this->assertSame(
                    $this->norm($this->resolve($vanilla, $attr, $property)),
                    $this->norm($this->resolve($greased, $attr, $property)),
                    "$greased resolve($attr, ".var_export($property, true).')',
                );
            }
        }

        $this->assertSame('trait_widgets', (new GreasedTraitFixture)->getTable());
        $this->assertSame(['from_trait'], (new GreasedTraitFixture)->getFillable());
    }

    public function test_integration_getters_route_through_the_override(): void
    {
        // Prove the framework actually calls the override: the attribute-derived getters on a
        // greased model match a vanilla model carrying the same attributes.
        $vanilla = new VanillaCAFixture;
        $greased = new GreasedCAFixture;

        $this->assertSame($vanilla->getTable(), $greased->getTable());
        $this->assertSame($vanilla->getFillable(), $greased->getFillable());
        $this->assertSame($vanilla->getHidden(), $greased->getHidden());
        $this->assertSame($vanilla->getVisible(), $greased->getVisible());
        $this->assertSame($vanilla->getAppends(), $greased->getAppends());
        $this->assertSame($vanilla->getConnectionName(), $greased->getConnectionName());
        $this->assertSame($vanilla->getDateFormat(), $greased->getDateFormat());
    }

    public function test_absent_attribute_is_memoized_as_null_in_the_carveout(): void
    {
        // The point of the tier: the common no-attribute case caches a null (not a re-resolve),
        // in the dedicated carve-out static keyed [class][attributeClass][property].
        $this->resolve(GreasedPlainFixture::class, Fillable::class, 'columns');

        $cache = (new \ReflectionProperty(GreasedPlainFixture::class, 'greaseClassAttributes'))->getValue();

        $this->assertArrayHasKey(GreasedPlainFixture::class, $cache);
        $this->assertArrayHasKey(Fillable::class, $cache[GreasedPlainFixture::class]);
        $this->assertArrayHasKey('columns', $cache[GreasedPlainFixture::class][Fillable::class]);
        $this->assertNull($cache[GreasedPlainFixture::class][Fillable::class]['columns']);
    }

    /** Invoke the protected static resolveClassAttribute via reflection. */
    private function resolve(string $class, string $attr, ?string $property = null): mixed
    {
        return (new ReflectionMethod($class, 'resolveClassAttribute'))->invoke(null, $attr, $property, $class);
    }

    /** Resolve two (attr, property) calls in order and return the second call's result. */
    private function secondOf(string $class, array $first, array $second, bool $fresh = false): mixed
    {
        if ($fresh && method_exists($class, 'flushGreaseBlueprint')) {
            $class::flushGreaseBlueprint();
        }

        $m = new ReflectionMethod($class, 'resolveClassAttribute');
        $m->invoke(null, $first[0], $first[1], $class);

        return $m->invoke(null, $second[0], $second[1], $class);
    }

    /** Normalize an attribute instance to a comparable array; pass scalars/null through. */
    private function norm(mixed $value): mixed
    {
        return is_object($value) ? ['__class' => $value::class] + get_object_vars($value) : $value;
    }
}

// --- Fixtures: identical attributes on a vanilla (oracle) and a greased class. ------------

#[Table(name: 'grease_widgets', incrementing: false)]
#[Fillable(['name', 'qty'])]
#[Hidden(['secret'])]
#[Visible(['name', 'qty'])]
#[Appends(['label'])]
#[Connection('alt')]
#[DateFormat('U')]
#[Touches(['owner'])]
class VanillaCAFixture extends Model {}

#[Table(name: 'grease_widgets', incrementing: false)]
#[Fillable(['name', 'qty'])]
#[Hidden(['secret'])]
#[Visible(['name', 'qty'])]
#[Appends(['label'])]
#[Connection('alt')]
#[DateFormat('U')]
#[Touches(['owner'])]
class GreasedCAFixture extends Model
{
    use HasGreasedClassAttributes;
}

class VanillaPlainFixture extends Model {}

class GreasedPlainFixture extends Model
{
    use HasGreasedClassAttributes;
}

#[Table(name: 'parent_widgets')]
class VanillaParentFixture extends Model {}
class VanillaChildFixture extends VanillaParentFixture {}

#[Table(name: 'parent_widgets')]
class GreasedParentFixture extends Model
{
    use HasGreasedClassAttributes;
}
class GreasedChildFixture extends GreasedParentFixture {}

#[Table(name: 'col', timestamps: false)]
class VanillaColAFixture extends Model {}
#[Table(name: 'col', timestamps: false)]
class GreasedColAFixture extends Model
{
    use HasGreasedClassAttributes;
}

#[Table(name: 'col', timestamps: false)]
class VanillaColBFixture extends Model {}
#[Table(name: 'col', timestamps: false)]
class GreasedColBFixture extends Model
{
    use HasGreasedClassAttributes;
}

#[Table(name: 'trait_widgets')]
#[Fillable(['from_trait'])]
trait DeclaresTraitAttributes {}

class VanillaTraitFixture extends Model
{
    use DeclaresTraitAttributes;
}
class VanillaTraitChildFixture extends VanillaTraitFixture {}
#[Table(name: 'own_widgets')]
class VanillaTraitOwnFixture extends Model
{
    use DeclaresTraitAttributes;
}

class GreasedTraitFixture extends Model
{
    use DeclaresTraitAttributes, HasGreasedClassAttributes;
}
class GreasedTraitChildFixture extends GreasedTraitFixture {}
#[Table(name: 'own_widgets')]
class GreasedTraitOwnFixture extends Model
{
    use DeclaresTraitAttributes, HasGreasedClassAttributes;
}
