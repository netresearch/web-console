<?php

declare(strict_types=1);

use Netresearch\WebConsole\WebConsole;

/**
 * Entry point for the web-console.
 *
 * Deploy path: drop the whole repo into your web root (or let a composer
 * consumer install it under vendor/netresearch/web-console/), point a
 * URL at this file, expose the WEBCONSOLE_* environment variables, and
 * the rest is handled by \Netresearch\WebConsole\WebConsole.
 */

// Locate composer's autoloader, both for the in-repo dev checkout and for
// the composer-consumer case where we live under vendor/netresearch/...
$autoloaders = [
    __DIR__ . '/.build/vendor/autoload.php', // dev checkout (composer.json config.vendor-dir)
    __DIR__ . '/vendor/autoload.php',        // standalone install
    __DIR__ . '/../../autoload.php',         // installed under vendor/netresearch/web-console/
];

$autoloaderFound = false;

foreach ($autoloaders as $autoloader) {
    if (is_file($autoloader)) {
        require $autoloader;
        $autoloaderFound = true;

        break;
    }
}

if (!$autoloaderFound) {
    http_response_code(500);
    fwrite(
        STDERR,
        'web-console: no composer autoloader found. Run `composer install` in the project root or re-install the package.' . PHP_EOL,
    );

    exit(1);
}

WebConsole::fromEnvironment()->run();
