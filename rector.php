<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Symfony\Symfony72\Rector\StmtsAwareInterface\PushRequestToRequestStackConstructorRector;
use Rector\ValueObject\PhpVersion;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withPhpVersion(PhpVersion::PHP_82)
    ->withComposerBased(symfony: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
    )
    ->withSkip([
        __DIR__ . '/demo',
        __DIR__ . '/vendor',
        // Keep classic AbstractExtension registration for broad Twig/Symfony compatibility.
        __DIR__ . '/src/Twig/SiteBackupExtension.php',
        // ~600-line TreeBuilder fluent chain: Rector 2.x scope analysis does not finish (child
        // process timeout), already on v1.16.0. Covered by PHPStan + ConfigurationTest.
        __DIR__ . '/src/DependencyInjection/Configuration.php',
        // Constructor RequestStack([...]) with a mutated Request must assign $request first;
        // PushRequestToRequestStackConstructorRector reorders and breaks those tests.
        PushRequestToRequestStackConstructorRector::class,
    ]);
