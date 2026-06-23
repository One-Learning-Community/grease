<?php

namespace Grease\Config;

use Illuminate\Console\Command;
use Illuminate\Support\Arr;

/**
 * `grease:config-cache` — Laravel's `config:cache`, plus an eager flat leaf-key index.
 *
 * Runs the framework's `config:cache` (so `bootstrap/cache/config.php` is fresh), then emits
 * a sibling `grease_config_flat.php` holding a flat `'a.b.c' => scalar` map of every leaf. The
 * file is a constant `return [...]`, so opcache interns it into shared memory — every FPM
 * request loads it ~free and every leaf `config()` read becomes a single hash hit with no
 * dot-walk and no warmup (and an Octane clone COW-inherits it). {@see GreaseConfigServiceProvider}
 * loads it at boot when it is at least as fresh as the config cache.
 *
 * Drop-in twin of `config:cache`: use it instead, and `config:clear` / a plain `config:cache`
 * simply leaves the (now-staler) index unused via the provider's freshness check.
 */
class ConfigCacheCommand extends Command
{
    protected $signature = 'grease:config-cache';

    protected $description = 'Cache the config (config:cache) plus an eager flat leaf-key index for lookups';

    public function handle(): int
    {
        $exit = $this->call('config:cache');
        if ($exit !== self::SUCCESS) {
            return $exit;
        }

        $config = require $this->laravel->getCachedConfigPath();

        ['index' => $index, 'dropped' => $dropped] = self::buildFlatIndex($config);

        // Written AFTER config:cache, so its mtime is >= the config cache's — the provider's
        // freshness check uses exactly that to ignore a stale index after a plain config:cache.
        file_put_contents(
            GreaseConfigServiceProvider::flatIndexPath($this->laravel),
            '<?php return '.var_export($index, true).';'.PHP_EOL
        );

        if ($dropped > 0) {
            $this->warn("$dropped ambiguous dotted key(s) excluded from the flat index (literal/nested collisions); those read via the vanilla path.");
        }

        $this->components->info('Greased config flat index cached ('.count($index).' leaf keys).');

        return self::SUCCESS;
    }

    /**
     * Build a parity-verified leaf-key flat index from a (cached) config array.
     *
     * `Arr::dot` flattens to leaves; we keep only scalar/null values (var_export-able, the
     * same constraint `config:cache` itself imposes) and verify each against `Arr::get` —
     * dropping the rare literal-dotted-key collision where the two would disagree, so the
     * index is byte-identical to vanilla for every key it contains.
     *
     * @param  array<string, mixed>  $config
     * @return array{index: array<string, scalar|null>, dropped: int}
     */
    public static function buildFlatIndex(array $config): array
    {
        $index = [];
        $dropped = 0;

        foreach (Arr::dot($config) as $key => $value) {
            if (! ($value === null || is_scalar($value))) {
                continue; // non-leaf (e.g. empty array) — not a fast-path value
            }
            if (Arr::get($config, $key) === $value) {
                $index[$key] = $value;
            } else {
                $dropped++;
            }
        }

        return ['index' => $index, 'dropped' => $dropped];
    }
}
