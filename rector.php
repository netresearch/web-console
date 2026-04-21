<?php

declare(strict_types=1);

use Rector\CodingStyle\Rector\Catch_\CatchExceptionNameMatchingTypeRector;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPrivateMethodRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUselessParamTagRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUselessReturnTagRector;
use Rector\DeadCode\Rector\Stmt\RemoveUnreachableStatementRector;
use Rector\Set\ValueObject\LevelSetList;
use Rector\Set\ValueObject\SetList;

return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->paths([
        __DIR__,
    ]);

    $rectorConfig->phpstanConfig(__DIR__ . '/phpstan.neon');
    $rectorConfig->importNames();
    $rectorConfig->removeUnusedImports();
    $rectorConfig->disableParallel();
    $rectorConfig->cacheDirectory(__DIR__ . '/.build/cache/.rector.cache');
    $rectorConfig->containerCacheDirectory(__DIR__ . '/.build/cache/.rector.container.cache');
    $rectorConfig->phpVersion(80200);

    // Define what rule sets will be applied
    $rectorConfig->sets([
        SetList::CODE_QUALITY,
        SetList::CODING_STYLE,
        SetList::DEAD_CODE,
        SetList::EARLY_RETURN,
        SetList::INSTANCEOF,
        SetList::PRIVATIZATION,
        SetList::TYPE_DECLARATION,
        SetList::TYPE_DECLARATION_DOCBLOCKS,
        LevelSetList::UP_TO_PHP_82,
    ]);

    // Skip some rules
    $rectorConfig->skip([
        CatchExceptionNameMatchingTypeRector::class,
        RemoveUnreachableStatementRector::class,
        RemoveUselessParamTagRector::class,
        RemoveUselessReturnTagRector::class,

        // Test fixtures intentionally declare unused helpers (e.g. the
        // private `secret()` on JsonRpcServerTestTarget asserts that the
        // dispatcher refuses to expose it).
        RemoveUnusedPrivateMethodRector::class => [
            __DIR__ . '/tests',
        ],

        // Paths
        __DIR__ . '/.build',
    ]);
};
