<?php

namespace Grease\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('widgets', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->integer('count');
            $table->boolean('active');
            $table->decimal('amount', 10, 2);
            $table->text('payload');                 // json cast
            $table->dateTime('happened_at')->nullable();
            $table->timestamps();
        });
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    /** A representative raw row, as a driver would return it. */
    protected function rawRow(array $overrides = []): array
    {
        return array_merge([
            'id' => 1,
            'name' => 'Widget',
            'count' => '7',
            'active' => '1',
            'amount' => '12.34',
            'payload' => '{"a":1,"b":[2,3]}',
            'happened_at' => '2026-01-02 03:04:05',
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ], $overrides);
    }
}
