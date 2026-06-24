<?php

namespace Grease\View;

use Illuminate\Console\Command;
use Illuminate\View\ViewName;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Throwable;

/**
 * `grease:view-cache` — Laravel's `view:cache`, plus an eager view-resolution index.
 *
 * `view:cache` compiles every Blade template but discards the *resolution* it computed along the
 * way — so at runtime `FileViewFinder::find()` re-stat-walks `paths × extensions` to map a view
 * name back to its file (and never memoizes a miss), and the engine re-hashes the path to its
 * compiled file per render. This command captures that thrown-away map: for every view it records
 * `name => source path` and `source path => compiled path`, emitted as a constant `return [...]`
 * file that opcache interns into shared memory. {@see GreaseViewServiceProvider} seeds the greased
 * finder/compiler from it at boot (when fresh), so resolution is a single array hit from request one.
 *
 * Drop-in twin of `view:cache`: it runs it first (compiling + clearing), then writes the index last
 * so the freshness guard holds. A later plain `view:cache` / `optimize` leaves the now-staler index
 * unused; a name not in the index resolves live. Build==runtime contract: rebuild on deploy (the
 * `config:cache` shape) — structural view changes (add/move/delete) need a rebuild, exactly like
 * `view:cache` itself.
 */
class ViewCacheCommand extends Command
{
    protected $signature = 'grease:view-cache';

    protected $description = 'Compile views (view:cache) plus an eager name→path resolution index';

    public function handle(): int
    {
        $exit = $this->call('view:cache');

        if ($exit !== self::SUCCESS) {
            return $exit;
        }

        $index = $this->buildIndex();

        file_put_contents(
            GreaseViewServiceProvider::indexPath($this->laravel),
            '<?php return '.var_export($index, true).';'.PHP_EOL
        );

        $this->components->info('Greased view index cached ('.count($index['finder']).' views).');

        return self::SUCCESS;
    }

    /**
     * Resolve every discoverable Blade view to its source + compiled path, using the live finder
     * and compiler — so each entry is byte-identical to what runtime would compute.
     *
     * @return array{finder: array<string, string>, compiled: array<string, string>}
     */
    protected function buildIndex(): array
    {
        $factory = $this->laravel['view'];
        $finder = $factory->getFinder();
        $compiler = $factory->getEngineResolver()->resolve('blade')->getCompiler();

        $finderMap = [];
        $compiledMap = [];

        foreach ($this->viewNames($finder) as $name) {
            if (isset($finderMap[$name])) {
                continue; // first (winning) resolution kept; shadowed duplicates skipped
            }

            try {
                $source = $finder->find($name); // authoritative — captures precedence by construction
            } catch (Throwable) {
                continue; // unresolvable derived name (rare) — leave it to live resolution
            }

            $finderMap[$name] = $source;
            $compiledMap[$source] = $compiler->getCompiledPath($source);
        }

        return ['finder' => $finderMap, 'compiled' => $compiledMap];
    }

    /**
     * Yield the normalized name of every `*.blade.php` view under the finder's paths and namespace
     * hints — the reverse of `getPossibleViewFiles()` (relative path, minus extension, slashes→dots,
     * `namespace::` prefix for hinted views).
     *
     * @return iterable<string>
     */
    protected function viewNames($finder): iterable
    {
        foreach ($finder->getPaths() as $path) {
            foreach ($this->bladeFiles($path) as $name) {
                yield $name;
            }
        }

        foreach ($finder->getHints() as $namespace => $paths) {
            foreach ((array) $paths as $path) {
                foreach ($this->bladeFiles($path) as $name) {
                    yield $namespace.'::'.$name;
                }
            }
        }
    }

    /**
     * Derive the dotted, extension-stripped view names under a single directory.
     *
     * @return iterable<string>
     */
    protected function bladeFiles(string $path): iterable
    {
        if (! is_dir($path)) {
            return;
        }

        foreach (Finder::create()->in($path)->name('*.blade.php')->files() as $file) {
            /** @var SplFileInfo $file */
            $relative = substr($file->getRelativePathname(), 0, -strlen('.blade.php'));

            yield ViewName::normalize(str_replace(DIRECTORY_SEPARATOR, '.', $relative));
        }
    }
}
