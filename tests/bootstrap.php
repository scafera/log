<?php

declare(strict_types=1);

$paths = [
    dirname(__DIR__) . '/vendor/autoload.php',
    dirname(__DIR__, 4) . '/autoload.php',
    dirname(__DIR__, 2) . '/milestone3/vendor/autoload.php',
];

$autoloader = null;

foreach ($paths as $path) {
    if (file_exists($path)) {
        $autoloader = require $path;
        break;
    }
}

if ($autoloader === null) {
    throw new \RuntimeException('Cannot find autoload.php');
}

$autoloader->addPsr4('Scafera\\Log\\Tests\\', __DIR__);
