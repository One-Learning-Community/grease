<?php

namespace Grease\Tests;

use Grease\View\Compiler;
use Grease\View\Props;
use Illuminate\Filesystem\Filesystem;
use Illuminate\View\ComponentAttributeBag;
use Illuminate\View\Compilers\BladeCompiler;
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

    public function test_greased_emit_uses_the_fast_path(): void
    {
        $php = (new Compiler(new Filesystem, sys_get_temp_dir()))->compileString("@props(['type' => 'button'])");

        $this->assertStringContainsString('Grease\\View\\Props::names(', $php);
        $this->assertStringContainsString('isset($__propNames[$__key])', $php);
        $this->assertStringNotContainsString('get_defined_vars()', $php);
        $this->assertStringNotContainsString('extractPropNames', $php);
    }

    public function test_each_props_site_gets_a_distinct_memo_key(): void
    {
        $compiler = new Compiler(new Filesystem, sys_get_temp_dir());

        $first = $compiler->compileString("@props(['a' => 1])");
        $second = $compiler->compileString("@props(['a' => 1])");

        // Same declaration, two sites — the emitted memo keys must differ so distinct
        // components never alias one another's name map.
        preg_match_all("/Props::names\\('([0-9a-f]+)'/", $first.$second, $m);
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
        $this->assertStringContainsString('Grease\\View\\Props::names(', $greased->compileString("@props(['x' => 1])"));
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
