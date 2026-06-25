<?php

namespace Grease\Config;

use Illuminate\Console\Command;

/**
 * `grease:config-clear` — the clear twin of {@see ConfigCacheCommand}, and a superset of the
 * framework's `config:clear`: it runs that (dropping `bootstrap/cache/config.php`) and then
 * unlinks the sibling `grease_config_flat.php` so no stale index is left on disk.
 *
 * Wired into `optimize:clear` (under the `config` key) by {@see GreaseConfigServiceProvider},
 * so it shadows the native `config:clear` there for opted-in apps. NB: because it is a superset,
 * running it also clears the framework config cache — the same shape as `grease:config-cache`
 * being a superset of `config:cache`.
 */
class ConfigClearCommand extends Command
{
    protected $signature = 'grease:config-clear';

    protected $description = 'Clear the config cache (config:clear) and the greased flat leaf-key index';

    public function handle(): int
    {
        $exit = $this->call('config:clear');
        if ($exit !== self::SUCCESS) {
            return $exit;
        }

        $flat = GreaseConfigServiceProvider::flatIndexPath($this->laravel);
        if (is_file($flat)) {
            @unlink($flat);
        }

        $this->components->info('Greased config flat index cleared.');

        return self::SUCCESS;
    }
}
