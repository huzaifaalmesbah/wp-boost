#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Composer post-install script for wp-boost.
 *
 * Called automatically after `composer install` / `composer global require`.
 * Syncs the latest vetted skills from huzaifaalmesbah/wp-boost@main.
 *
 * This file is referenced by composer.json's "scripts" → "post-install-cmd".
 */

// Find autoloader — could be vendor/autoload.php or __DIR__/../vendor/autoload.php
$autoloader = null;
$candidates = [
    __DIR__ . '/../vendor/autoload.php',   // when installed as dependency
    __DIR__ . '/vendor/autoload.php',       // when developing the package itself
    dirname(__DIR__, 3) . '/autoload.php',  // global install: vendor/huzaifaalmesbah/wp-boost/scripts/../...
];

foreach ($candidates as $path) {
    if (file_exists($path)) {
        $autoloader = $path;
        break;
    }
}

if ($autoloader === null) {
    // Can't load classes — skip silently
    exit(0);
}

require $autoloader;

use Huzaifa\WpBoost\Setup\PostInstallSync;

PostInstallSync::run();

exit(0);
