<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
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
        // Constructor RequestStack([...]) with a mutated Request must assign $request first;
        // PushRequestToRequestStackConstructorRector reorders and breaks those tests.
        \Rector\Symfony\Symfony72\Rector\StmtsAwareInterface\PushRequestToRequestStackConstructorRector::class,
    ]);
