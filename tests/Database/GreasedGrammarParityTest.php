<?php

namespace Grease\Tests\Database;

use Grease\Database\MariaDbConnection as GreasedMariaDb;
use Grease\Database\MySqlConnection as GreasedMySql;
use Grease\Database\PostgresConnection as GreasedPostgres;
use Grease\Database\Query\Grammars\MariaDbGrammar as GreasedMariaDbGrammar;
use Grease\Database\Query\Grammars\MySqlGrammar as GreasedMySqlGrammar;
use Grease\Database\Query\Grammars\PostgresGrammar as GreasedPostgresGrammar;
use Illuminate\Database\Connection;
use Illuminate\Database\MariaDbConnection as VanillaMariaDb;
use Illuminate\Database\MySqlConnection as VanillaMySql;
use Illuminate\Database\PostgresConnection as VanillaPostgres;
use Illuminate\Database\Query\Expression;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The wrap-memo contract: a greased query grammar must produce the resolved SQL — `wrap()`,
 * `columnize()`, and full `compileSelect()` — byte-for-byte like vanilla, on the cache miss, the
 * cache hit, and after a table-prefix change (which must flush the memo). Oracle = the vanilla
 * driver grammar. A throwaway in-memory PDO stands in for the real driver — grammar wrapping never
 * touches the PDO, only the connection's table prefix.
 */
class GreasedGrammarParityTest extends TestCase
{
    /** @return array<string, array{0: class-string, 1: class-string, 2: class-string}> */
    public static function drivers(): array
    {
        return [
            // greased connection, vanilla connection, greased grammar class
            'mysql' => [GreasedMySql::class, VanillaMySql::class, GreasedMySqlGrammar::class],
            'mariadb' => [GreasedMariaDb::class, VanillaMariaDb::class, GreasedMariaDbGrammar::class],
            'pgsql' => [GreasedPostgres::class, VanillaPostgres::class, GreasedPostgresGrammar::class],
        ];
    }

    private static function connect(string $class): Connection
    {
        return new $class(new PDO('sqlite::memory:'), '', '', []);
    }

    private static function query(Connection $conn)
    {
        return $conn->query()
            ->from('posts')
            ->select('posts.id', 'posts.title', 'posts.created_at', 'authors.name as author_name')
            ->join('authors', 'authors.id', '=', 'posts.author_id')
            ->where('posts.published', '=', 1)
            ->whereIn('posts.status', ['live', 'featured'])
            ->orderBy('posts.created_at', 'desc')
            ->limit(20);
    }

    #[DataProvider('drivers')]
    public function test_grammar_is_greased(string $greased, string $vanilla, string $grammar): void
    {
        $this->assertInstanceOf($grammar, self::connect($greased)->getQueryGrammar());
    }

    #[DataProvider('drivers')]
    public function test_compile_select_is_byte_identical(string $greased, string $vanilla, string $grammar): void
    {
        $vanillaGrammar = self::connect($vanilla)->getQueryGrammar();
        $greasedGrammar = self::connect($greased)->getQueryGrammar();

        $query = self::query(self::connect($greased));

        $expected = $vanillaGrammar->compileSelect($query);
        $miss = $greasedGrammar->compileSelect($query);
        $hit = $greasedGrammar->compileSelect($query);

        $this->assertSame($expected, $miss, 'greased miss must equal vanilla SQL');
        $this->assertSame($expected, $hit, 'greased hit must equal vanilla SQL');
    }

    #[DataProvider('drivers')]
    public function test_wrap_shapes_are_byte_identical(string $greased, string $vanilla, string $grammar): void
    {
        $vanillaGrammar = self::connect($vanilla)->getQueryGrammar();
        $greasedGrammar = self::connect($greased)->getQueryGrammar();

        foreach (['id', 'posts.id', 'posts.id as pid', '*', 'data->meta->name', 'a.b.c', ''] as $shape) {
            $this->assertSame(
                $vanillaGrammar->wrap($shape),
                $greasedGrammar->wrap($shape),
                "wrap('$shape') diverged",
            );
            // Second call exercises the memo hit.
            $this->assertSame($vanillaGrammar->wrap($shape), $greasedGrammar->wrap($shape));
        }

        // An Expression must bypass the memo and match vanilla.
        $expr = new Expression('count(*)');
        $this->assertSame($vanillaGrammar->wrap($expr), $greasedGrammar->wrap($expr));
    }

    #[DataProvider('drivers')]
    public function test_columnize_is_byte_identical(string $greased, string $vanilla, string $grammar): void
    {
        $columns = ['posts.id', 'posts.title as t', 'authors.name', '*'];

        $this->assertSame(
            self::connect($vanilla)->getQueryGrammar()->columnize($columns),
            self::connect($greased)->getQueryGrammar()->columnize($columns),
        );
    }

    #[DataProvider('drivers')]
    public function test_wrap_table_shapes_are_byte_identical(string $greased, string $vanilla, string $grammar): void
    {
        $vanillaGrammar = self::connect($vanilla)->getQueryGrammar();
        $greasedGrammar = self::connect($greased)->getQueryGrammar();

        foreach (['posts', 'public.posts', 'posts as p', 'public.posts as p'] as $shape) {
            $this->assertSame(
                $vanillaGrammar->wrapTable($shape),
                $greasedGrammar->wrapTable($shape),
                "wrapTable('$shape') diverged (miss)",
            );
            // Second call exercises the memo hit.
            $this->assertSame(
                $vanillaGrammar->wrapTable($shape),
                $greasedGrammar->wrapTable($shape),
                "wrapTable('$shape') diverged (hit)",
            );
        }

        // An Expression must bypass the memo and match vanilla.
        $expr = new Expression('(select 1)');
        $this->assertSame($vanillaGrammar->wrapTable($expr), $greasedGrammar->wrapTable($expr));

        // An explicit non-null prefix bypasses the memo (its key domain isn't the connection prefix).
        $this->assertSame(
            $vanillaGrammar->wrapTable('posts', 'x_'),
            $greasedGrammar->wrapTable('posts', 'x_'),
        );
    }

    #[DataProvider('drivers')]
    public function test_wrap_table_is_memoized(string $greased, string $vanilla, string $grammar): void
    {
        $greasedGrammar = self::connect($greased)->getQueryGrammar();
        $greasedGrammar->wrapTable('posts');

        $memo = new \ReflectionProperty($greasedGrammar, 'greaseWrapTableMemo');
        $this->assertSame(['posts' => $greasedGrammar->wrapTable('posts')], $memo->getValue($greasedGrammar));
    }

    /**
     * The one invariant: a dotted identifier's first segment is prefixed, so a prefix change must
     * flush the memo. After `setTablePrefix()` the greased wrap must match vanilla again.
     */
    #[DataProvider('drivers')]
    public function test_table_prefix_change_flushes_memo(string $greased, string $vanilla, string $grammar): void
    {
        $greasedConn = self::connect($greased);
        $vanillaConn = self::connect($vanilla);

        // Warm both memos (wrap + wrapTable) at the empty prefix.
        $this->assertSame(
            $vanillaConn->getQueryGrammar()->wrap('posts.id'),
            $greasedConn->getQueryGrammar()->wrap('posts.id'),
        );
        $this->assertSame(
            $vanillaConn->getQueryGrammar()->wrapTable('posts'),
            $greasedConn->getQueryGrammar()->wrapTable('posts'),
        );

        // Change the prefix on both — the greased connection flushes its grammar memos.
        $greasedConn->setTablePrefix('pfx_');
        $vanillaConn->setTablePrefix('pfx_');

        $expected = $vanillaConn->getQueryGrammar()->wrap('posts.id');
        $this->assertStringContainsString('pfx_posts', $expected, 'sanity: prefix should appear');
        $this->assertSame(
            $expected,
            $greasedConn->getQueryGrammar()->wrap('posts.id'),
            'after prefix change the greased wrap memo must be flushed and match vanilla',
        );

        $expectedTable = $vanillaConn->getQueryGrammar()->wrapTable('posts');
        $this->assertStringContainsString('pfx_posts', $expectedTable, 'sanity: prefix should appear on table');
        $this->assertSame(
            $expectedTable,
            $greasedConn->getQueryGrammar()->wrapTable('posts'),
            'after prefix change the greased wrapTable memo must be flushed and match vanilla',
        );
    }
}
