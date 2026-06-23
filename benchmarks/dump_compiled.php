<?php
require __DIR__.'/../vendor/autoload.php';
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Illuminate\View\Component;
use Orchestra\Testbench\Foundation\Application;
$VIEWS = __DIR__.'/blade/views';
Component::flushCache(); Component::forgetComponentsResolver(); Component::forgetFactory(); Facade::clearResolvedInstances();
$cache = sys_get_temp_dir().'/grease-dump'; @mkdir($cache,0777,true); array_map('unlink', glob("$cache/*.php")?:[]);
$app = Application::create(basePath:null, resolvingCallback:null, options:['enabled_package_discoveries'=>false]);
$app['config']->set('view.compiled', $cache);
$app['view']->addLocation($VIEWS);
(require __DIR__.'/blade/register.php')($app);
Container::setInstance($app); Facade::setFacadeApplication($app);
$app['view']->make('page-app', ['count'=>1])->render();
$want = $argv[1] ?? 'page-app';
foreach (glob("$cache/*.php") as $f) {
    $src = file_get_contents($f);
    $first = strtok($src, "\n");
    // crude: match a sentinel string per view
    if ($want === 'page-app' && str_contains($src, 'feed') && str_contains($src, 'mb-4')) { echo $src; exit; }
    if ($want === 'card' && str_contains($src, 'card-head')) { echo $src; exit; }
}

