<?php

namespace Grease\Routing;

use Illuminate\Console\Command;

/**
 * `grease:route-clear` — the clear twin of {@see RouteMiddlewareCacheCommand}, and a superset of
 * the framework's `route:clear`: it runs that (dropping the route cache) and then unlinks the
 * sibling `grease_routes_mw.php` index so no stale index is left on disk.
 *
 * Wired into `optimize:clear` (under the `routes` key) by {@see GreaseRoutingServiceProvider}, so
 * it shadows the native `route:clear` there for opted-in apps. NB: because it is a superset,
 * running it also clears the route cache — the same shape as `grease:route-cache` being a superset
 * of `route:cache`.
 */
class RouteMiddlewareClearCommand extends Command
{
    protected $signature = 'grease:route-clear';

    protected $description = 'Clear the route cache (route:clear) and the greased route-middleware index';

    public function handle(): int
    {
        $exit = $this->call('route:clear');
        if ($exit !== self::SUCCESS) {
            return $exit;
        }

        $index = GreaseRoutingServiceProvider::indexPath($this->laravel);
        if (is_file($index)) {
            @unlink($index);
        }

        $this->components->info('Greased route-middleware index cleared.');

        return self::SUCCESS;
    }
}
