<?php

namespace Grease\Tests;

use Grease\View\Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * {@see Factory::yieldContent()} collapses vanilla's three sequential `str_replace`
 * passes (over the whole, often page-sized, section content) into one
 * `preg_replace_callback`. The contract is byte-identical output. This asserts it
 * directly: for any content, the greased single-pass must equal the verbatim vanilla
 * three-pass algorithm using the *same* parent placeholder — across plain text, each
 * marker in isolation, adjacency/overlap edge cases, and the pathological internal
 * `--parent--holder--` literal.
 */
class FactorySectionParityTest extends TestCase
{
    /**
     * The exact vanilla `ManagesLayouts::yieldContent` rewrite — the oracle.
     */
    private function vanillaThreePass(string $content, string $placeholder): string
    {
        $content = str_replace('@@parent', '--parent--holder--', $content);

        return str_replace(
            '--parent--holder--', '@parent', str_replace($placeholder, '', $content)
        );
    }

    #[DataProvider('contents')]
    public function test_yield_content_matches_vanilla_three_pass(string $template): void
    {
        $factory = (new ReflectionClass(Factory::class))->newInstanceWithoutConstructor();
        $placeholder = Factory::parentPlaceholder('content');

        // {PH} stands in for the real per-section placeholder so the data provider can
        // place it without knowing the random salt.
        $content = str_replace('{PH}', $placeholder, $template);

        $sections = (new ReflectionClass(\Illuminate\View\Factory::class))->getProperty('sections');
        $sections->setValue($factory, ['content' => $content]);

        $this->assertSame(
            $this->vanillaThreePass($content, $placeholder),
            $factory->yieldContent('content'),
        );
    }

    public function test_string_default_when_section_missing_matches_vanilla(): void
    {
        $factory = (new ReflectionClass(Factory::class))->newInstanceWithoutConstructor();
        $placeholder = Factory::parentPlaceholder('missing');

        // No section set: vanilla escapes the default, then runs the three passes.
        foreach (['plain default', 'amp & lt <', 'a @@parent b'] as $default) {
            $this->assertSame(
                $this->vanillaThreePass(e($default), $placeholder),
                $factory->yieldContent('missing', $default),
            );
        }
    }

    /** @return array<string, array{0: string}> */
    public static function contents(): array
    {
        return [
            'plain text' => ['<p>Hello world</p>'],
            'empty' => [''],
            'literal @@parent' => ['before @@parent after'],
            'unfilled placeholder' => ['head {PH} tail'],
            'pathological --parent--holder-- literal' => ['x --parent--holder-- y'],
            'three adjacent ats' => ['@@@parent'],
            'two adjacent @@parent' => ['@@parent@@parent'],
            'placeholder then @@parent' => ['{PH}@@parent'],
            '@@parent then placeholder' => ['@@parent{PH}'],
            'all markers mixed' => ['{PH} a @@parent b --parent--holder-- c {PH}'],
            'placeholder run' => ['{PH}{PH}{PH}'],
            'realistic body, no markers' => [str_repeat('<article><p>row</p></article>', 200)],
            'realistic body with one @parent' => [str_repeat('<li>x</li>', 100).'{PH}'.str_repeat('<li>y</li>', 100)],
        ];
    }
}
