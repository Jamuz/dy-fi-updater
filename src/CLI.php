<?php

namespace Metabolix\DyFiUpdater;

spl_autoload_register(function ($class) {
    $n = explode("\\", __NAMESPACE__);
    $c = explode("\\", $class);
    $f = __DIR__ . "/" . array_pop($c) . ".php";
    if ($n == $c && file_exists($f)) {
        require_once $f;
    }
});

exit(Updater::runApplication());
