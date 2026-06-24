<?php

namespace Grease\Tests\View;

use Grease\View\FileViewFinder as GreasedFinder;
use Illuminate\Filesystem\Filesystem;
use Illuminate\View\FileViewFinder as VanillaFinder;
use PHPUnit\Framework\TestCase;

/**
 * The eager view-index contract: a greased finder must (1) return an indexed name's path as a
 * pre-seeded hit, (2) resolve any non-indexed name byte-identically to vanilla via live fallback,
 * and (3) keep the index across `flush()` (it lives outside `$views`). Oracle = vanilla
 * {@see VanillaFinder}.
 */
class GreasedViewFinderTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/grease_views_'.getmypid().'_'.uniqid();
        @mkdir($this->dir.'/mail', 0777, true);
        file_put_contents($this->dir.'/foo.blade.php', 'foo');
        file_put_contents($this->dir.'/mail/message.blade.php', 'msg');
    }

    protected function tearDown(): void
    {
        @unlink($this->dir.'/foo.blade.php');
        @unlink($this->dir.'/mail/message.blade.php');
        @rmdir($this->dir.'/mail');
        @rmdir($this->dir);
    }

    private function vanilla(): VanillaFinder
    {
        return new VanillaFinder(new Filesystem, [$this->dir]);
    }

    public function test_indexed_name_is_a_seeded_hit(): void
    {
        $finder = GreasedFinder::fromBase($this->vanilla());
        $finder->useGreaseViewIndex(['some.view' => '/seeded/path.blade.php']);

        // Returned verbatim from the index — no stat, no disk lookup required.
        $this->assertSame('/seeded/path.blade.php', $finder->find('some.view'));
    }

    public function test_indexed_hit_equals_what_vanilla_resolves(): void
    {
        $vanilla = $this->vanilla();
        $expected = $vanilla->find('foo'); // the real resolved path

        $finder = GreasedFinder::fromBase($this->vanilla());
        $finder->useGreaseViewIndex(['foo' => $expected]); // built from the live resolver

        $this->assertSame($expected, $finder->find('foo'));
    }

    public function test_non_indexed_name_falls_through_to_vanilla(): void
    {
        $vanilla = $this->vanilla();
        $finder = GreasedFinder::fromBase($this->vanilla());
        $finder->useGreaseViewIndex(['unrelated' => '/x.php']);

        // 'foo' isn't in the index → live resolution, identical to vanilla.
        $this->assertSame($vanilla->find('foo'), $finder->find('foo'));
    }

    public function test_index_survives_flush(): void
    {
        $finder = GreasedFinder::fromBase($this->vanilla());
        $finder->useGreaseViewIndex(['some.view' => '/seeded/path.blade.php']);

        $finder->flush(); // resets the live $views memo, not the eager index

        $this->assertSame('/seeded/path.blade.php', $finder->find('some.view'));
    }

    public function test_from_base_copies_state(): void
    {
        $finder = GreasedFinder::fromBase($this->vanilla());

        // Paths carried over → live resolution still works (and namespaced too).
        $this->assertSame($this->vanilla()->find('foo'), $finder->find('foo'));
    }
}
