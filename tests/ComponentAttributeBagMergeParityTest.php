<?php

namespace Grease\Tests;

use Grease\View\ComponentAttributeBag as GreasedBag;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\ComponentAttributeBag as VanillaBag;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * {@see GreasedBag} reimplements one hot method — `merge()` — with plain array loops
 * instead of vanilla's Collection pipeline. The contract: for any live attributes and any
 * defaults, the greased merge must produce the byte-identical bag the vanilla merge does
 * — same resolved attribute array (keys, values, ORDER) and therefore the same rendered
 * HTML string. This asserts that A/B by running both bags through the same inputs.
 */
class ComponentAttributeBagMergeParityTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $attributes  the live (incoming) attributes
     * @param  array<string, mixed>  $defaults  the merge() default map
     */
    #[DataProvider('scenarios')]
    public function test_greased_merge_matches_vanilla(array $attributes, array $defaults, bool $escape = true): void
    {
        $vanilla = (new VanillaBag($attributes))->merge($defaults, $escape);
        $greased = (new GreasedBag($attributes))->merge($defaults, $escape);

        // Same resolved attributes, in the same order — array key order is significant
        // because __toString() emits attributes in iteration order.
        $this->assertSame($vanilla->getAttributes(), $greased->getAttributes(), 'merged attributes diverged');

        // And the rendered HTML string — the actual product — is identical.
        $this->assertSame((string) $vanilla, (string) $greased, 'rendered attribute string diverged');
    }

    public function test_appendable_default_matches_vanilla(): void
    {
        // prepends() wraps a value as an AppendableAttributeValue — the non-class/style
        // appendable path. Exercise it through both bags.
        $attributes = ['data-controller' => 'modal'];

        $vanilla = (new VanillaBag($attributes));
        $greased = (new GreasedBag($attributes));

        $vMerged = $vanilla->merge(['data-controller' => $vanilla->prepends('base')]);
        $gMerged = $greased->merge(['data-controller' => $greased->prepends('base')]);

        $this->assertSame($vMerged->getAttributes(), $gMerged->getAttributes());
        $this->assertSame((string) $vMerged, (string) $gMerged);
    }

    public function test_merge_returns_a_greased_bag(): void
    {
        // merge() returns `new static`, so the fast path stays live down any chain.
        $this->assertInstanceOf(GreasedBag::class, (new GreasedBag(['class' => 'a']))->merge(['class' => 'b']));
    }

    public function test_greased_bag_is_recognized_as_a_component_attribute_bag(): void
    {
        // A bag forwarded as an attribute *value* (a parent passing `$attributes` down to
        // a nested component) must pass through sanitizeComponentAttribute() untouched —
        // not escaped/stringified like a generic Stringable. The framework guards that with
        // `! $value instanceof ComponentAttributeBag` (CompilesComponents::sanitize-
        // ComponentAttribute), which our subclass satisfies precisely because it `extends`
        // the base. A standalone reimplementation would be e()'d here and diverge.
        $greased = new GreasedBag(['class' => 'mt-4']);
        $vanilla = new VanillaBag(['class' => 'mt-4']);

        $this->assertInstanceOf(VanillaBag::class, $greased);
        $this->assertSame($greased, BladeCompiler::sanitizeComponentAttribute($greased));
        $this->assertSame($vanilla, BladeCompiler::sanitizeComponentAttribute($vanilla));

        // Control: a plain Stringable IS escaped — proving the guard above is load-bearing.
        $stringable = new class
        {
            public function __toString(): string
            {
                return '<b>x</b>';
            }
        };
        $this->assertSame('&lt;b&gt;x&lt;/b&gt;', BladeCompiler::sanitizeComponentAttribute($stringable));
    }

    /** @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>, 2?: bool}> */
    public static function scenarios(): array
    {
        return [
            'class append (the common case)' => [
                ['class' => 'mt-4'],
                ['class' => 'avatar avatar-md'],
            ],
            'style append gets a trailing semicolon' => [
                ['style' => 'color: red'],
                ['style' => 'font-weight: bold'],
            ],
            'class and style together' => [
                ['class' => 'mt-4', 'style' => 'color: red'],
                ['class' => 'btn', 'style' => 'margin: 0'],
            ],
            'non-appendable attribute falls through unchanged' => [
                ['id' => 'go', 'data-x' => '1'],
                ['class' => 'btn'],
            ],
            'live class with no matching default' => [
                ['class' => 'mt-4', 'id' => 'x'],
                [],
            ],
            'default-only key (no live attribute)' => [
                ['class' => 'mt-4'],
                ['type' => 'button', 'class' => 'btn'],
            ],
            'duplicate class values are de-duplicated' => [
                ['class' => 'btn mt-4'],
                ['class' => 'btn'],
            ],
            'empty live attributes' => [
                [],
                ['class' => 'btn', 'type' => 'button'],
            ],
            'empty defaults' => [
                ['class' => 'mt-4', 'id' => 'x'],
                [],
            ],
            'default value gets HTML-escaped' => [
                ['id' => 'x'],
                ['data-json' => '{"a":1 & 2}', 'title' => 'a "quote" <tag>'],
            ],
            'escape=false leaves default values raw' => [
                ['id' => 'x'],
                ['data-json' => '{"a":1 & 2}'],
                false,
            ],
            'bool/null/object defaults skip escaping' => [
                ['class' => 'mt-4'],
                ['disabled' => true, 'hidden' => null, 'class' => 'btn'],
            ],
            'ordering: defaults first, then extra live attributes' => [
                ['class' => 'mt-4', 'id' => 'z', 'data-a' => '1'],
                ['type' => 'button', 'class' => 'btn'],
            ],
            'falsy class values are filtered before implode' => [
                ['class' => ''],
                ['class' => 'btn'],
            ],
        ];
    }
}
