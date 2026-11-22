<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPublicMethodParameterRector;
use Rector\Symfony\CodeQuality\Rector\Class_\EventSubscriberToAttributeRector;
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
        // Public API: these subscribers implement EventSubscriberInterface with a public static getSubscribedEvents();
        // converting them to #[AsEventListener] removes both in a minor, and ProductionSecretsGuardSubscriber is excluded
        // from the resource scan and registered by SiteBackupExtension, so it would lose its listeners.
        EventSubscriberToAttributeRector::class => [
            __DIR__ . '/src/EventSubscriber/ColdStartSchemaGateSubscriber.php',
            __DIR__ . '/src/EventSubscriber/ProductionSecretsGuardSubscriber.php',
            __DIR__ . '/src/EventSubscriber/SetupDbDoneRedirectSubscriber.php',
            __DIR__ . '/src/EventSubscriber/UnlocalizedDefaultLocaleSubscriber.php',
        ],
        RemoveUnusedPublicMethodParameterRector::class => [
            // castForVarDumper() is a VarDumper caster callback: VarDumper calls it with ($object, $array, ...); the
            // public signature stays as the caster contract documents it.
            __DIR__ . '/src/Model/ArchivePassword.php',
            // The fake platforms mirror Doctrine DBAL's AbstractPlatform signatures (duck-typed); dropping $column would
            // make them diverge from the API they stand in for.
            __DIR__ . '/tests/Unit/Setup/Storage/PortableSetupDdlTest.php',
        ],
    ]);
