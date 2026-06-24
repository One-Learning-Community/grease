<?php

namespace Grease\Tests\Database;

use Grease\Database\GreaseDatabaseServiceProvider;
use Grease\Database\MariaDbConnection;
use Grease\Database\MySqlConnection;
use Grease\Database\PostgresConnection;
use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * The provider registers a `Connection::resolverFor()` per supported driver, each yielding the
 * greased connection class. Connection resolvers are process-global statics, so this resets them
 * after the test to avoid leaking into others.
 */
class GreaseDatabaseServiceProviderTest extends TestCase
{
    protected function tearDown(): void
    {
        (new ReflectionProperty(Connection::class, 'resolvers'))->setValue(null, []);

        parent::tearDown();
    }

    public function test_registers_greased_resolvers_for_each_driver(): void
    {
        (new GreaseDatabaseServiceProvider(new Container))->register();

        $expected = [
            'mysql' => MySqlConnection::class,
            'mariadb' => MariaDbConnection::class,
            'pgsql' => PostgresConnection::class,
        ];

        foreach ($expected as $driver => $class) {
            $resolver = Connection::getResolver($driver);
            $this->assertIsCallable($resolver, "no resolver registered for [$driver]");

            $connection = $resolver(new PDO('sqlite::memory:'), '', '', []);
            $this->assertInstanceOf($class, $connection);
        }
    }

    public function test_unsupported_drivers_are_untouched(): void
    {
        (new GreaseDatabaseServiceProvider(new Container))->register();

        $this->assertNull(Connection::getResolver('sqlite'));
        $this->assertNull(Connection::getResolver('sqlsrv'));
    }
}
