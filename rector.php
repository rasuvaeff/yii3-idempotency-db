<?php

declare(strict_types=1);

use Rasuvaeff\RectorNamedLiterals\AddNameToLiteralArgumentRector;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPrivateMethodParameterRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withPhpSets(php83: true)
    ->withPreparedSets(deadCode: true, codeQuality: true)
    ->withSkip([
        // `ConfigWiringTest::loadDb()` hands `$params` to the `require`d
        // `config/di.php` through variable scope, which rector cannot see: it
        // reads the parameter as dead and strips it, silently turning every
        // params-driven wiring assertion into a test of the defaults.
        RemoveUnusedPrivateMethodParameterRector::class => [
            __DIR__ . '/tests/Integration/ConfigWiringTest.php',
        ],
    ])
    ->withRules([AddNameToLiteralArgumentRector::class]);
