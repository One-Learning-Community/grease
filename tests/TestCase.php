<?php

namespace Grease\Tests;

use Grease\Tests\Fixtures\GreasedSample;
use Grease\Tests\Fixtures\SampleData;
use Grease\Tests\Fixtures\VanillaSample;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // A fixed key so the `encrypted` cast can be exercised deterministically.
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('g', 32)));
        $app['config']->set('app.cipher', 'AES-256-CBC');
    }

    /**
     * A fully-populated raw row, as a database driver would return it (strings).
     * Covers every cast type in DefinesSampleCasts (encrypted is added per-test).
     */
    protected function sampleRow(array $overrides = []): array
    {
        return array_merge(SampleData::row(), $overrides);
    }

    /** Every cast column (excludes id; includes the timestamp date columns). */
    protected function castColumns(): array
    {
        return [
            'int_val', 'real_val', 'float_val', 'dec_val', 'str_val', 'bool_val',
            'obj_val', 'arr_val', 'json_val', 'coll_val', 'date_val', 'dt_val',
            'cdt_val', 'imm_date_val', 'imm_dt_val', 'icdt_val', 'ts_val',
            'hashed_val', 'status_val', 'upper_val', 'created_at', 'updated_at',
        ];
    }

    /**
     * Hydrate the same raw row into a vanilla and a greased model.
     *
     * @return array{0: VanillaSample, 1: GreasedSample}
     */
    protected function pair(array $row): array
    {
        return [
            (new VanillaSample)->newFromBuilder($row),
            (new GreasedSample)->newFromBuilder($row),
        ];
    }
}
