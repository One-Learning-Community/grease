<?php

namespace Grease\View;

use Illuminate\Console\Command;

/**
 * `grease:view-clear` — the clear twin of {@see ViewCacheCommand}, and a superset of the
 * framework's `view:clear`: it runs that (dropping the compiled views) and then unlinks the
 * sibling `grease_views.php` index so no stale index is left on disk.
 *
 * Wired into `optimize:clear` (under the `views` key) by {@see GreaseViewServiceProvider}, so it
 * shadows the native `view:clear` there for opted-in apps. NB: because it is a superset, running
 * it also clears the compiled views — the same shape as `grease:view-cache` being a superset of
 * `view:cache`.
 */
class ViewClearCommand extends Command
{
    protected $signature = 'grease:view-clear';

    protected $description = 'Clear the compiled views (view:clear) and the greased view index';

    public function handle(): int
    {
        $exit = $this->call('view:clear');
        if ($exit !== self::SUCCESS) {
            return $exit;
        }

        $index = GreaseViewServiceProvider::indexPath($this->laravel);
        if (is_file($index)) {
            @unlink($index);
        }

        $this->components->info('Greased view index cleared.');

        return self::SUCCESS;
    }
}
