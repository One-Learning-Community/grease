<?php

namespace Grease\Tests;

use Grease\View\Compiler;
use Grease\View\Props;
use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\ComponentAttributeBag;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The greased Blade compiler rewrites only ONE emit — `@props` resolution — so the
 * contract is: a component compiled through {@see Compiler} must resolve the exact
 * same prop variables and leave the exact same pass-through attributes as one compiled
 * through the stock {@see BladeCompiler}, for any `@props` declaration and any incoming
 * attribute set. This asserts that A/B by compiling each declaration both ways and
 * executing the emitted PHP against the same attributes.
 */
class ViewPropsParityTest extends TestCase
{
    protected function setUp(): void
    {
        Props::flush();
    }

    /**
     * @param  array<array-key, mixed>  $declaration  the `@props([...])` argument source
     * @param  array<string, mixed>  $incoming  attributes the parent passes in
     */
    #[DataProvider('scenarios')]
    public function test_greased_props_resolution_matches_vanilla(string $declaration, array $incoming): void
    {
        $directive = "@props({$declaration})";

        $vanilla = $this->execute((new BladeCompiler(new Filesystem, sys_get_temp_dir()))->compileString($directive), $incoming);
        $greased = $this->execute((new Compiler(new Filesystem, sys_get_temp_dir()))->compileString($directive), $incoming);

        $this->assertSame($vanilla['props'], $greased['props'], 'resolved prop variables diverged');
        $this->assertSame($vanilla['attributes'], $greased['attributes'], 'surviving attributes diverged');
    }

    /**
     * The load-bearing `@include` case: a prop also exists as a scope local because the
     * parent passed it as include data (`@include('sub', ['propValue' => 1])` extracts
     * `$propValue = 1` before the `@props` block runs). Vanilla's emit reads that local
     * (`$$key = $$key ?? ...`) so the passed value wins over the declared default; the
     * greased emit must do the same. Regression for the "renders the default, ignores the
     * passed value" bug.
     *
     * @param  array<string, mixed>  $locals  scope locals present before the block (the include data)
     * @param  array<string, mixed>  $incoming  attributes the parent passes in
     */
    #[DataProvider('scopeScenarios')]
    public function test_greased_props_resolution_honors_existing_scope_locals(string $declaration, array $locals, array $incoming): void
    {
        $directive = "@props({$declaration})";

        $vanilla = $this->executeWithLocals((new BladeCompiler(new Filesystem, sys_get_temp_dir()))->compileString($directive), $locals, $incoming);
        $greased = $this->executeWithLocals((new Compiler(new Filesystem, sys_get_temp_dir()))->compileString($directive), $locals, $incoming);

        $this->assertSame($vanilla['props'], $greased['props'], 'resolved prop variables diverged with pre-seeded scope locals');
        $this->assertSame($vanilla['attributes'], $greased['attributes'], 'surviving attributes diverged with pre-seeded scope locals');
    }

    public function test_greased_emit_uses_the_fast_path(): void
    {
        $php = (new Compiler(new Filesystem, sys_get_temp_dir()))->compileString("@props(['type' => 'button'])");

        $this->assertStringContainsString('Grease\\View\\Props::mergeAttributes(', $php);
        $this->assertStringContainsString('$$__key = $$__key ?? $__value', $php);   // scope-deferring bind loop, not extract()
        $this->assertStringNotContainsString('extract(', $php);
        $this->assertStringNotContainsString('get_defined_vars()', $php);
        $this->assertStringNotContainsString('extractPropNames', $php);
        $this->assertStringNotContainsString('array_filter(', $php);
        $this->assertStringNotContainsString('in_array(', $php);
    }

    public function test_each_props_site_gets_a_distinct_memo_key(): void
    {
        $compiler = new Compiler(new Filesystem, sys_get_temp_dir());

        $first = $compiler->compileString("@props(['a' => 1])");
        $second = $compiler->compileString("@props(['a' => 1])");

        // Same declaration, two sites — the emitted memo keys must differ so distinct
        // components never alias one another's name map.
        preg_match_all("/Props::mergeAttributes\\('([0-9a-f]+)'/", $first.$second, $m);
        $this->assertCount(2, array_unique($m[1]), 'two @props sites shared a memo key');
    }

    public function test_from_base_carries_over_registered_directives(): void
    {
        $base = new BladeCompiler(new Filesystem, sys_get_temp_dir());
        $base->directive('greeting', fn ($e) => "<?php echo 'hi'; ?>");

        $greased = Compiler::fromBase($base);

        $this->assertInstanceOf(Compiler::class, $greased);
        $this->assertStringContainsString("echo 'hi'", $greased->compileString('@greeting'));
        // and the override is live on the cloned instance
        $this->assertStringContainsString('Grease\\View\\Props::mergeAttributes(', $greased->compileString("@props(['x' => 1])"));
    }

    public function test_class_component_opening_seeds_a_greased_attribute_bag(): void
    {
        $greased = Compiler::compileClassComponentOpening('App\\View\\Card', "'card'", '[]', 'abc123');
        $vanilla = BladeCompiler::compileClassComponentOpening('App\\View\\Card', "'card'", '[]', 'abc123');

        // The seed line is the only addition, and vanilla never had it.
        $seed = '$component->attributes ??= new \\Grease\\View\\ComponentAttributeBag([]);';
        $this->assertStringContainsString($seed, $greased);
        $this->assertStringNotContainsString($seed, $vanilla);

        // It must land AFTER shouldRender() (only when rendering) and BEFORE the
        // startComponent that calls data() — data() captures the bag, so a later seed
        // would be too late. That ordering is the whole mechanism.
        $afterShouldRender = strpos($greased, '$component->shouldRender()');
        $seedPos = strpos($greased, $seed);
        $startComponent = strpos($greased, '$__env->startComponent(');
        $this->assertNotFalse($seedPos);
        $this->assertGreaterThan($afterShouldRender, $seedPos, 'seed must follow shouldRender()');
        $this->assertLessThan($startComponent, $seedPos, 'seed must precede startComponent()/data()');

        // Nothing else changed: resolve/withName/startComponent are all still emitted.
        foreach (['::resolve(', '$component->withName(', '$__env->startComponent('] as $needle) {
            $this->assertStringContainsString($needle, $greased);
        }
    }

    public function test_compiled_path_matches_vanilla_and_is_memoized(): void
    {
        $cache = sys_get_temp_dir();
        $vanilla = new BladeCompiler(new Filesystem, $cache);
        $greased = new Compiler(new Filesystem, $cache);

        // The memoized override must return the byte-for-byte same compiled path the
        // base computes — for several distinct paths — and be stable across calls.
        foreach (['components.avatar', 'page-app', 'partials.nav', 'x'] as $path) {
            $this->assertSame(
                $vanilla->getCompiledPath($path),
                $greased->getCompiledPath($path),
                "compiled path diverged for {$path}",
            );
            // Second call hits the memo; identical to the first and to vanilla.
            $this->assertSame($vanilla->getCompiledPath($path), $greased->getCompiledPath($path));
        }
    }

    /**
     * Execute compiled `@props` PHP against an attribute bag and capture what a
     * component body would see: the resolved prop locals and the surviving attributes.
     *
     * @param  array<string, mixed>  $incoming
     * @return array{props: array<string, mixed>, attributes: array<string, mixed>}
     */
    private function execute(string $compiled, array $incoming): array
    {
        $run = static function (ComponentAttributeBag $attributes) use ($compiled): array {
            eval('?>'.$compiled);

            $vars = get_defined_vars();
            unset($vars['attributes'], $vars['compiled']);

            foreach (array_keys($vars) as $name) {
                if (str_starts_with((string) $name, '__')) {
                    unset($vars[$name]);
                }
            }

            return ['props' => $vars, 'attributes' => $attributes->getAttributes()];
        };

        return $run(new ComponentAttributeBag($incoming));
    }

    /**
     * Like {@see execute()}, but pre-seeds the scope with `$locals` before running the
     * compiled `@props` block — modelling a parent that passed those names as `@include`
     * data (which `extract()`s them into scope before the block runs). The captured prop
     * locals therefore reflect vanilla's "existing local wins" precedence.
     *
     * @param  array<string, mixed>  $locals
     * @param  array<string, mixed>  $incoming
     * @return array{props: array<string, mixed>, attributes: array<string, mixed>}
     */
    private function executeWithLocals(string $compiled, array $locals, array $incoming): array
    {
        $run = static function (ComponentAttributeBag $attributes) use ($compiled, $locals): array {
            extract($locals);

            eval('?>'.$compiled);

            $vars = get_defined_vars();
            unset($vars['attributes'], $vars['compiled'], $vars['locals']);

            foreach (array_keys($vars) as $name) {
                if (str_starts_with((string) $name, '__')) {
                    unset($vars[$name]);
                }
            }

            return ['props' => $vars, 'attributes' => $attributes->getAttributes()];
        };

        return $run(new ComponentAttributeBag($incoming));
    }

    /** @return array<string, array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}> */
    public static function scopeScenarios(): array
    {
        return [
            // The reported bug: an include passes the value, no attribute carries it —
            // the passed local must beat the default.
            'include data beats the default' => [
                "['propValue' => 0]",
                ['propValue' => 1],
                [],
            ],
            'include data present, default would otherwise apply' => [
                "['type' => 'primary', 'size' => 'md']",
                ['size' => 'lg'],
                [],
            ],
            // Existing local outranks an attribute of the same name, too.
            'existing local outranks a passed attribute' => [
                "['type' => 'primary']",
                ['type' => 'fromInclude'],
                ['type' => 'fromAttribute'],
            ],
            // A scope local that collides with a *pass-through* (non-prop) attribute is
            // unset by vanilla's cleanup — the greased emit must unset it as well.
            'pass-through attribute shadows a scope local' => [
                "['type' => 'a']",
                ['class' => 'localValue'],
                ['class' => 'btn', 'id' => 'go'],
            ],
            'list-style prop seeded by include data' => [
                "['readonly', 'required']",
                ['readonly' => true],
                [],
            ],
        ];
    }

    /** @return array<string, array{0: string, 1: array<string, mixed>}> */
    public static function scenarios(): array
    {
        return [
            'no attributes, defaults apply' => [
                "['type' => 'primary', 'size' => 'md']",
                [],
            ],
            'some props passed, some attributes fall through' => [
                "['type' => 'primary', 'size' => 'md']",
                ['type' => 'submit', 'class' => 'btn', 'id' => 'go'],
            ],
            'camelCase prop reached via its kebab alias' => [
                "['iconName' => null, 'fullWidth' => false]",
                ['icon-name' => 'check', 'full-width' => true, 'class' => 'x'],
            ],
            'camelCase prop reached via its camel name' => [
                "['iconName' => null]",
                ['iconName' => 'star'],
            ],
            'list-style props (no defaults)' => [
                "['readonly', 'required']",
                ['readonly' => true, 'type' => 'text'],
            ],
            'mixed list and keyed props' => [
                "['disabled', 'type' => 'button']",
                ['disabled' => true, 'type' => 'submit', 'data-x' => '1'],
            ],
            'prop explicitly overridden to null stays as passed' => [
                "['label' => 'Default']",
                ['label' => null],
            ],
            'all attributes are pass-through (no props match)' => [
                "['type' => 'a']",
                ['class' => 'c', 'wire:click' => 'go', 'x-data' => '{}'],
            ],
            'numeric-looking attribute keys' => [
                "['type' => 'a']",
                ['type' => 'b', 'tabindex' => '0', 'data-n' => 5],
            ],
        ];
    }
}
