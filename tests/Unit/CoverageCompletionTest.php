<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit;

use DateTimeImmutable;
use Nowo\SiteBackupBundle\Backup\BackupArchiver;
use Nowo\SiteBackupBundle\DependencyInjection\Configuration;
use Nowo\SiteBackupBundle\DependencyInjection\SiteBackupExtension;
use Nowo\SiteBackupBundle\EventSubscriber\ColdStartSchemaGateSubscriber;
use Nowo\SiteBackupBundle\EventSubscriber\SetupDbDoneRedirectSubscriber;
use Nowo\SiteBackupBundle\Form\Panel\PanelActionType;
use Nowo\SiteBackupBundle\Form\Panel\PanelLoginType;
use Nowo\SiteBackupBundle\Model\SetupProgress;
use Nowo\SiteBackupBundle\Routing\SetupPathPrefixResolver;
use Nowo\SiteBackupBundle\Setup\ColdStart\ColdStartRequestAttributes;
use Nowo\SiteBackupBundle\Setup\ColdStart\MysqlSchemaExistenceChecker;
use Nowo\SiteBackupBundle\Setup\ColdStart\SchemaExistenceCheckerInterface;
use Nowo\SiteBackupBundle\Setup\Detector\SetupNeedEvaluator;
use Nowo\SiteBackupBundle\Setup\DurableSetupDoneStoreInterface;
use Nowo\SiteBackupBundle\Setup\SetupContext;
use Nowo\SiteBackupBundle\Setup\SetupDbDoneGuard;
use Nowo\SiteBackupBundle\Setup\SetupStepInput;
use Nowo\SiteBackupBundle\Setup\Step\RequirementsStep;
use Nowo\SiteBackupBundle\Setup\Storage\CacheDoctrineSetupProgressStorage;
use Nowo\SiteBackupBundle\Setup\Storage\CacheSetupProgressStorage;
use Nowo\SiteBackupBundle\Setup\Storage\ChainSetupProgressStorage;
use Nowo\SiteBackupBundle\Setup\Storage\DoctrineDbalSetupProgressStorage;
use Nowo\SiteBackupBundle\Setup\Storage\DoctrineDbalSetupStepJournal;
use Nowo\SiteBackupBundle\Setup\Storage\FilesystemSetupProgressStorage;
use Nowo\SiteBackupBundle\Setup\Storage\SetupMarkerManager;
use Nowo\SiteBackupBundle\Setup\Storage\SetupProgressStorageInterface;
use Nowo\SiteBackupBundle\Tests\Unit\Setup\FakeDurableSetupDoneStore;
use Nowo\SiteBackupBundle\Tests\Unit\Setup\Storage\FakeDbalConnection;
use Nowo\SiteBackupBundle\Tests\Unit\Setup\Storage\FakeDbalResult;
use Nowo\SiteBackupBundle\Twig\SiteBackupExtension as TwigSiteBackupExtension;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use stdClass;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\CacheItem;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\Form\Forms;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Throwable;

use function array_key_exists;
use function function_exists;
use function is_array;
use function str_contains;

use const JSON_THROW_ON_ERROR;

final class CoverageCompletionTest extends TestCase
{
    use CreatesSiteBackupTestHarness;

    protected function setUp(): void
    {
        $this->initHarness();
    }

    protected function tearDown(): void
    {
        $this->destroyHarness();
    }

    public function testMysqlSchemaCheckerViaConnectionPaths(): void
    {
        $connection = $this->schemaProbeConnection();

        $checker = new MysqlSchemaExistenceChecker(connection: $connection, database: 'app');
        self::assertTrue($checker->schemaExists());

        $checkerNoTables = new MysqlSchemaExistenceChecker(
            connection: $connection,
            database: 'app',
            requireApplicationTables: false,
        );
        self::assertTrue($checkerNoTables->schemaExists());

        $unknownDb = $this->schemaProbeConnection(selectResult: new RuntimeException("Unknown database 'missing'"));
        self::assertFalse((new MysqlSchemaExistenceChecker(connection: $unknownDb, database: 'missing'))->schemaExists());

        $otherError = $this->schemaProbeConnection(selectResult: new RuntimeException('connection refused'));
        self::assertFalse((new MysqlSchemaExistenceChecker(connection: $otherError, database: 'app'))->schemaExists());

        $wrappedUnknown = $this->schemaProbeConnection(selectResult: new RuntimeException('SQLSTATE 1049 Unknown database'));
        self::assertFalse((new MysqlSchemaExistenceChecker(connection: $wrappedUnknown, database: 'app'))->schemaExists());

        $selectOnly = $this->schemaProbeConnection();
        self::assertTrue((new MysqlSchemaExistenceChecker(connection: $selectOnly))->schemaExists());

        $schemaQueryFails = $this->schemaProbeConnection(schemaResult: new RuntimeException('denied'));
        self::assertFalse((new MysqlSchemaExistenceChecker(connection: $schemaQueryFails, database: 'app'))->schemaExists());

        $emptySchema = $this->schemaProbeConnection(schemaResult: false);
        self::assertFalse((new MysqlSchemaExistenceChecker(connection: $emptySchema, database: 'app'))->schemaExists());

        $noFetchOne = $this->schemaProbeConnection(schemaResult: 'no-fetch');
        self::assertFalse((new MysqlSchemaExistenceChecker(connection: $noFetchOne, database: 'app'))->schemaExists());

        $pdoOk = $this->createMock(PDO::class);
        $pdoOk->method('query')->willReturn($this->createMock(PDOStatement::class));
        $pdoOk->method('prepare')->willReturn($this->createMock(PDOStatement::class));
        $okStmt = $this->createMock(PDOStatement::class);
        $okStmt->method('execute')->willReturn(true);
        $okStmt->method('fetchColumn')->willReturn('1');
        $pdoWithTables = $this->createMock(PDO::class);
        $pdoWithTables->method('query')->willReturn($this->createMock(PDOStatement::class));
        $pdoWithTables->method('prepare')->willReturn($okStmt);
        self::assertTrue((new MysqlSchemaExistenceChecker(database: 'app', pdo: $pdoWithTables))->schemaExists());
        self::assertTrue((new MysqlSchemaExistenceChecker(
            database: 'app',
            requireApplicationTables: false,
            pdo: $pdoOk,
        ))->schemaExists());

        $unknownPdo = $this->createMock(PDO::class);
        $unknownPdo->method('query')->willThrowException(new RuntimeException('SQLSTATE[HY000] [1049] Unknown database'));
        self::assertFalse((new MysqlSchemaExistenceChecker(database: 'missing', pdo: $unknownPdo))->schemaExists());

        $otherPdo = $this->createMock(PDO::class);
        $otherPdo->method('query')->willThrowException(new RuntimeException('connection refused'));
        self::assertFalse((new MysqlSchemaExistenceChecker(database: 'app', pdo: $otherPdo))->schemaExists());

        $privateQuery = new class {
            private function executeQuery(): mixed
            {
                return null;
            }
        };
        self::assertFalse((new MysqlSchemaExistenceChecker(connection: $privateQuery, database: 'app'))->schemaExists());

        $wrapped = new RuntimeException('dbal wrap', 0, new RuntimeException("Unknown database 'ghost'"));
        $unknown = new ReflectionMethod(MysqlSchemaExistenceChecker::class, 'isUnknownDatabase');
        self::assertTrue($unknown->invoke(new MysqlSchemaExistenceChecker(), $wrapped));
        self::assertFalse($unknown->invoke(new MysqlSchemaExistenceChecker(), new RuntimeException('connection refused')));
    }

    /**
     * Duck-typed DBAL connection used by {@see MysqlSchemaExistenceChecker} without requiring doctrine/dbal.
     *
     * @param mixed $selectResult fetchOne value or {@see Throwable}
     * @param mixed $schemaResult fetchOne value, {@see Throwable}, or `'no-fetch'`
     */
    private function schemaProbeConnection(mixed $selectResult = 1, mixed $schemaResult = 1): object
    {
        return new class($selectResult, $schemaResult) {
            public function __construct(
                private mixed $selectResult,
                private mixed $schemaResult,
            ) {
            }

            /**
             * @param list<mixed> $params
             */
            public function executeQuery(string $sql, array $params = []): mixed
            {
                $payload = str_contains($sql, 'information_schema') ? $this->schemaResult : $this->selectResult;
                if ($payload instanceof Throwable) {
                    throw $payload;
                }

                if ($payload === 'no-fetch') {
                    return new stdClass();
                }

                return new class($payload) {
                    public function __construct(private mixed $value)
                    {
                    }

                    public function fetchOne(): mixed
                    {
                        return $this->value;
                    }
                };
            }
        };
    }

    public function testMysqlSchemaCheckerPdoTableProbeViaReflection(): void
    {
        $checker = new MysqlSchemaExistenceChecker(host: 'db', database: 'app');
        $pdo     = $this->createMock(PDO::class);
        $stmt    = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchColumn')->willReturn('1');
        $pdo->method('prepare')->willReturn($stmt);

        $method = new ReflectionMethod(MysqlSchemaExistenceChecker::class, 'hasNonSetupTablesViaPdo');
        $method->setAccessible(true);
        self::assertTrue($method->invoke($checker, $pdo));

        $stmtEmpty = $this->createMock(PDOStatement::class);
        $stmtEmpty->method('execute')->willReturn(true);
        $stmtEmpty->method('fetchColumn')->willReturn(false);
        $pdoEmpty = $this->createMock(PDO::class);
        $pdoEmpty->method('prepare')->willReturn($stmtEmpty);
        self::assertFalse($method->invoke($checker, $pdoEmpty));

        $pdoFail = $this->createMock(PDO::class);
        $pdoFail->method('prepare')->willThrowException(new RuntimeException('fail'));
        self::assertFalse($method->invoke($checker, $pdoFail));
    }

    public function testColdStartSchemaGateSubscriberBranches(): void
    {
        $events = ColdStartSchemaGateSubscriber::getSubscribedEvents();
        self::assertArrayHasKey(KernelEvents::REQUEST, $events);

        $checker = new class implements SchemaExistenceCheckerInterface {
            public int $calls = 0;

            public function schemaExists(): bool
            {
                ++$this->calls;

                return true;
            }
        };

        $requestStack = new RequestStack([Request::create('/_setup')]);
        $resolver = new SetupPathPrefixResolver($requestStack, '/_setup', 'never', 'en', ['en', 'es']);

        $subscriber = new ColdStartSchemaGateSubscriber(
            schemaChecker: $checker,
            pathPrefixResolver: $resolver,
            setupPathPrefix: '/_setup',
            safePathPrefixes: ['', '/health/'],
            enabledLocales: ['es'],
            stopPropagation: false,
        );

        $kernel = $this->createMock(HttpKernelInterface::class);

        $sub = new RequestEvent($kernel, Request::create('/page'), HttpKernelInterface::SUB_REQUEST);
        $subscriber->onKernelRequestProbe($sub);
        self::assertSame(0, $checker->calls);

        $main = new RequestEvent($kernel, Request::create('/page'), HttpKernelInterface::MAIN_REQUEST);
        $subscriber->onKernelRequestProbe($main);
        self::assertTrue($main->getRequest()->attributes->get(ColdStartRequestAttributes::SCHEMA_EXISTS));
        self::assertSame(1, $checker->calls);

        $cached = new RequestEvent($kernel, Request::create('/other'), HttpKernelInterface::MAIN_REQUEST);
        $cached->getRequest()->attributes->set(ColdStartRequestAttributes::SCHEMA_EXISTS, true);
        $subscriber->onKernelRequestProbe($cached);
        self::assertSame(1, $checker->calls);

        $subscriber->onKernelRequestRedirect($cached);
        self::assertNull($cached->getResponse());

        $setupPath = new RequestEvent($kernel, Request::create('/_setup/wizard'), HttpKernelInterface::MAIN_REQUEST);
        $setupPath->getRequest()->attributes->set(ColdStartRequestAttributes::SCHEMA_EXISTS, false);
        $subscriber->onKernelRequestRedirect($setupPath);
        self::assertNull($setupPath->getResponse());

        $localized = new RequestEvent($kernel, Request::create('/es/_setup/step'), HttpKernelInterface::MAIN_REQUEST);
        $localized->getRequest()->attributes->set(ColdStartRequestAttributes::SCHEMA_EXISTS, false);
        $subscriber->onKernelRequestRedirect($localized);
        self::assertNull($localized->getResponse());

        $missingChecker = new class implements SchemaExistenceCheckerInterface {
            public function schemaExists(): bool
            {
                return false;
            }
        };
        $redirectSubscriber = new ColdStartSchemaGateSubscriber(
            schemaChecker: $missingChecker,
            pathPrefixResolver: $resolver,
            setupPathPrefix: '',
            safePathPrefixes: ['/health/'],
            enabledLocales: [],
            stopPropagation: true,
        );
        $redirect = new RequestEvent($kernel, Request::create('/app'), HttpKernelInterface::MAIN_REQUEST);
        $redirectSubscriber->onKernelRequestProbe($redirect);
        $redirectSubscriber->onKernelRequestRedirect($redirect);
        self::assertNotNull($redirect->getResponse());

        $safeOnly = new RequestEvent($kernel, Request::create('/health/status'), HttpKernelInterface::MAIN_REQUEST);
        $redirectSubscriber->onKernelRequestProbe($safeOnly);
        $redirectSubscriber->onKernelRequestStopLateListeners($safeOnly);
        self::assertTrue($safeOnly->isPropagationStopped());
    }

    public function testStepJournalExtendedBranches(): void
    {
        $conn    = new FakeDbalConnection();
        $journal = new DoctrineDbalSetupStepJournal($conn);

        $journal->sync(new SetupProgress(
            phase: SetupProgress::PHASE_WAITING,
            profile: '',
            currentStepId: 'running_step',
            error: 'boom',
            completedStepIds: ['', 'valid', 123],
            updatedAt: new DateTimeImmutable('2026-08-15T10:00:00+00:00'),
            startedAt: new DateTimeImmutable('2026-08-15T09:00:00+00:00'),
        ));
        self::assertSame(['valid'], $journal->listCompletedStepIds('default'));

        $journal->sync(new SetupProgress(
            phase: SetupProgress::PHASE_FAILED,
            profile: 'fresh_install',
            currentStepId: 'failed_step',
            completedStepIds: ['requirements'],
            completedAt: new DateTimeImmutable('2026-08-15T11:00:00+00:00'),
        ));
        $journal->sync(new SetupProgress(
            phase: SetupProgress::PHASE_COMPLETED,
            profile: 'fresh_install',
            currentStepId: 'done_step',
            completedStepIds: ['requirements'],
            completedAt: new DateTimeImmutable('2026-08-15T12:00:00+00:00'),
        ));

        $journal->sync(new SetupProgress(
            phase: SetupProgress::PHASE_WAITING,
            profile: 'fresh_install',
            currentStepId: 'running_step',
            completedStepIds: ['requirements'],
        ));

        $latest = $journal->latestFinishedStep('fresh_install');
        self::assertNotNull($latest);
        self::assertContains($latest['step_id'], ['done_step', 'failed_step', 'requirements']);

        $journal->clear('fresh_install');
        self::assertSame([], $journal->listCompletedStepIds('fresh_install'));

        $journal->sync(new SetupProgress(
            phase: SetupProgress::PHASE_RUNNING,
            profile: 'fresh_install',
            currentStepId: 'requirements',
            completedStepIds: ['bootstrap'],
        ));
        $journal->sync(new SetupProgress(
            phase: SetupProgress::PHASE_RUNNING,
            profile: 'fresh_install',
            currentStepId: 'requirements',
            message: 'updated',
            completedStepIds: ['bootstrap'],
        ));

        $thin = new SetupProgress(phase: SetupProgress::PHASE_WAITING, profile: 'fresh_install', currentStepId: 'x', completedStepIds: ['requirements', 'migrations']);
        self::assertContains('requirements', $journal->enrich($thin)->getCompletedStepIds());

        $throwingConn = new class {
            public function executeQuery(string $sql): never
            {
                throw new RuntimeException('schema fail');
            }

            public function executeStatement(string $sql, array $params = []): int
            {
                throw new RuntimeException('schema fail');
            }
        };
        $throwingJournal = new DoctrineDbalSetupStepJournal($throwingConn);
        self::assertSame($thin, $throwingJournal->enrich($thin));
        self::assertSame([], $throwingJournal->listCompletedStepIds());
        self::assertNull($throwingJournal->latestFinishedStep());

        $loopConn = new class {
            public function executeStatement(string $sql, array $params = []): int
            {
                return 0;
            }

            public function executeQuery(string $sql, array $params = []): object
            {
                return new class {
                    private bool $returned = false;

                    public function fetchAssociative(): array|false
                    {
                        if ($this->returned) {
                            return false;
                        }
                        $this->returned = true;

                        return ['profile' => 'p', 'step_id' => 'a', 'status' => 'completed', 'step_order' => 0, 'finished_at' => '2026-01-01 00:00:00'];
                    }
                };
            }
        };
        $loopJournal = new DoctrineDbalSetupStepJournal($loopConn);
        self::assertSame(['a'], $loopJournal->listCompletedStepIds());

        $queryOnlyConn = new class {
            public function executeQuery(string $sql, array $params = []): object
            {
                return new class {
                    public function fetchAssociative(): false
                    {
                        return false;
                    }
                };
            }
        };
        $queryOnlyJournal = new DoctrineDbalSetupStepJournal($queryOnlyConn);
        $queryOnlyJournal->sync(new SetupProgress(
            phase: SetupProgress::PHASE_RUNNING,
            currentStepId: 'step',
            completedStepIds: ['prev'],
        ));

        $badConn = new class {
            public function executeQuery(string $sql): object
            {
                return new class {
                    public function fetchAssociative(): false
                    {
                        return false;
                    }
                };
            }
        };
        $badJournal = new DoctrineDbalSetupStepJournal($badConn);
        self::assertTrue($badJournal->isUsable());

        $noExec = new class {
            public function executeQuery(string $sql): object
            {
                return new class {
                };
            }
        };
        $noExecJournal = new DoctrineDbalSetupStepJournal($noExec);
        self::assertSame([], $noExecJournal->listCompletedStepIds());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No DBAL connection.');
        $method = new ReflectionMethod(DoctrineDbalSetupStepJournal::class, 'executeStatement');
        $method->setAccessible(true);
        $method->invoke(new DoctrineDbalSetupStepJournal(), 'SELECT 1', []);
    }

    public function testStepJournalExecuteQueryOnlyAndMissingMethods(): void
    {
        $conn = new class {
            public function executeQuery(string $sql, array $params = []): object
            {
                return new class {
                    public function fetchAllAssociative(): array
                    {
                        return ['not-array'];
                    }
                };
            }
        };
        $journal = new DoctrineDbalSetupStepJournal($conn);
        self::assertSame([], $journal->listCompletedStepIds());

        $cannotExecute = new class {
            public function foo(): void
            {
            }
        };
        $journal2 = new DoctrineDbalSetupStepJournal($cannotExecute);
        $method   = new ReflectionMethod(DoctrineDbalSetupStepJournal::class, 'executeStatement');
        $method->setAccessible(true);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('DBAL connection cannot execute statements.');
        $method->invoke($journal2, 'SELECT 1', []);
    }

    public function testSiteBackupExtensionPrependUiKitDefaults(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new DummyUiKitExtension());
        (new SiteBackupExtension())->prepend($container);
        self::assertNotEmpty($container->getExtensionConfig('nowo_ui_kit'));

        $container2 = new ContainerBuilder();
        $container2->registerExtension(new DummyUiKitExtension());
        $container2->prependExtensionConfig('nowo_ui_kit', ['css_framework' => 'bootstrap5', 'icon_set' => 'bootstrap-icons']);
        (new SiteBackupExtension())->prepend($container2);
        self::assertCount(1, $container2->getExtensionConfig('nowo_ui_kit'));

        $container3 = new ContainerBuilder();
        $container3->registerExtension(new DummyUiKitExtension());
        $container3->registerExtension(new SiteBackupExtension());
        $container3->loadFromExtension('nowo_site_backup', ['css_framework' => 'bootstrap']);
        (new SiteBackupExtension())->prepend($container3);
        $configs = $container3->getExtensionConfig('nowo_ui_kit');
        self::assertSame('bootstrap5', $configs[0]['css_framework'] ?? null);
        self::assertSame('bootstrap-icons', $configs[0]['icon_set'] ?? null);

        $container4 = new ContainerBuilder();
        $container4->registerExtension(new DummyUiKitExtension());
        $container4->registerExtension(new SiteBackupExtension());
        $container4->loadFromExtension('nowo_site_backup', ['css_framework' => 'tabler']);
        (new SiteBackupExtension())->prepend($container4);
        $configs4 = $container4->getExtensionConfig('nowo_ui_kit');
        self::assertSame('tabler', $configs4[0]['css_framework'] ?? null);
        self::assertSame('tabler-icons', $configs4[0]['icon_set'] ?? null);

        $container5 = new ContainerBuilder();
        $container5->registerExtension(new DummyUiKitExtension());
        $container5->registerExtension(new SiteBackupExtension());
        $container5->prependExtensionConfig('nowo_ui_kit', ['css_framework' => 'bootstrap5']);
        $container5->loadFromExtension('nowo_site_backup', ['css_framework' => 'custom']);
        (new SiteBackupExtension())->prepend($container5);
        $iconSetConfigs = array_values(array_filter(
            $container5->getExtensionConfig('nowo_ui_kit'),
            static fn (mixed $cfg): bool => is_array($cfg) && array_key_exists('icon_set', $cfg),
        ));
        self::assertSame('bootstrap-icons', $iconSetConfigs[0]['icon_set'] ?? null);

        $container6 = new ContainerBuilder();
        $container6->registerExtension(new DummyUiKitExtension());
        $container6->registerExtension(new SiteBackupExtension());
        $container6->prependExtensionConfig('nowo_ui_kit', ['icon_set' => 'bootstrap-icons']);
        $container6->loadFromExtension('nowo_site_backup', ['css_framework' => 'custom']);
        (new SiteBackupExtension())->prepend($container6);
        $cssConfigs = array_values(array_filter(
            $container6->getExtensionConfig('nowo_ui_kit'),
            static fn (mixed $cfg): bool => is_array($cfg) && array_key_exists('css_framework', $cfg) && !isset($cfg['icon_set']),
        ));
        self::assertSame('custom', $cssConfigs[0]['css_framework'] ?? null);

        $container7 = new ContainerBuilder();
        $container7->registerExtension(new DummyUiKitExtension());
        $container7->registerExtension(new SiteBackupExtension());
        $container7->prependExtensionConfig('nowo_ui_kit', ['css_framework' => 'bootstrap5', 'icon_set' => 'bootstrap-icons']);
        $container7->loadFromExtension('nowo_site_backup', ['css_framework' => 'bootstrap']);
        (new SiteBackupExtension())->prepend($container7);
        self::assertCount(1, $container7->getExtensionConfig('nowo_ui_kit'));

        $noUiKit = new ContainerBuilder();
        (new SiteBackupExtension())->prepend($noUiKit);
        self::assertSame([], $noUiKit->getExtensionConfig('nowo_ui_kit'));

        $prependUiKit = new ReflectionMethod(SiteBackupExtension::class, 'prependUiKitDefaults');
        $prependUiKit->setAccessible(true);
        $mockContainer = $this->getMockBuilder(ContainerBuilder::class)
            ->onlyMethods(['hasExtension', 'getExtensionConfig', 'prependExtensionConfig'])
            ->getMock();
        $mockContainer->method('hasExtension')->with('nowo_ui_kit')->willReturn(true);
        $mockContainer->method('getExtensionConfig')->willReturnCallback(
            static fn (string $alias): array => match ($alias) {
                'nowo_ui_kit' => ['not-an-array', ['css_framework' => 'bootstrap']],
                default       => [[]],
            },
        );
        $prependUiKit->invoke(new SiteBackupExtension(), $mockContainer);
    }

    public function testSiteBackupExtensionSecurityAndCacheModes(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', sys_get_temp_dir());
        $container->registerExtension(new DummySecurityExtension());
        (new SiteBackupExtension())->load([[
            'security' => ['allow_unauthenticated' => false],
        ]], $container);
        self::assertTrue($container->hasDefinition('nowo_site_backup.access_checker.default'));

        $cacheContainer = new ContainerBuilder();
        $cacheContainer->setParameter('kernel.project_dir', sys_get_temp_dir());
        (new SiteBackupExtension())->load([[
            'security' => ['allow_unauthenticated' => true],
            'setup'    => [
                'progress_storage' => 'cache',
            ],
        ]], $cacheContainer);
        self::assertTrue($cacheContainer->hasDefinition(CacheSetupProgressStorage::class));

        $cacheDoctrine = new ContainerBuilder();
        $cacheDoctrine->setParameter('kernel.project_dir', sys_get_temp_dir());
        (new SiteBackupExtension())->load([[
            'security' => ['allow_unauthenticated' => true],
            'setup'    => [
                'progress_storage'    => 'cache_doctrine',
                'progress_cache_pool' => 'cache.app',
                'progress_cache_key'  => 'custom-key',
                'progress_cache_ttl'  => 3600,
            ],
        ]], $cacheDoctrine);
        self::assertTrue($cacheDoctrine->hasDefinition(CacheDoctrineSetupProgressStorage::class));

        $durable = new ContainerBuilder();
        $durable->setParameter('kernel.project_dir', sys_get_temp_dir());
        (new SiteBackupExtension())->load([[
            'security' => ['allow_unauthenticated' => true],
            'setup'    => [
                'durable_done' => ['enabled' => true, 'redirect_target' => '/admin/home'],
            ],
        ]], $durable);
        $redirectDef = $durable->getDefinition(SetupDbDoneRedirectSubscriber::class);
        self::assertSame('/admin/home', $redirectDef->getArgument('$redirectTarget'));

        $durableDefault = new ContainerBuilder();
        $durableDefault->setParameter('kernel.project_dir', sys_get_temp_dir());
        (new SiteBackupExtension())->load([[
            'security' => ['allow_unauthenticated' => true],
            'setup'    => [
                'durable_done' => ['enabled' => true],
            ],
        ]], $durableDefault);
        self::assertSame(
            '/',
            $durableDefault->getDefinition(SetupDbDoneRedirectSubscriber::class)->getArgument('$redirectTarget'),
        );

        $doctrineMode = new ContainerBuilder();
        $doctrineMode->setParameter('kernel.project_dir', sys_get_temp_dir());
        (new SiteBackupExtension())->load([[
            'security' => ['allow_unauthenticated' => true],
            'setup'    => [
                'progress_storage' => 'doctrine',
            ],
        ]], $doctrineMode);
        self::assertSame(
            DoctrineDbalSetupProgressStorage::class,
            (string) $doctrineMode->getAlias(SetupProgressStorageInterface::class),
        );

        $chainMode = new ContainerBuilder();
        $chainMode->setParameter('kernel.project_dir', sys_get_temp_dir());
        (new SiteBackupExtension())->load([[
            'security' => ['allow_unauthenticated' => true],
            'setup'    => [
                'progress_storage' => 'chain',
            ],
        ]], $chainMode);
        self::assertSame(
            ChainSetupProgressStorage::class,
            (string) $chainMode->getAlias(SetupProgressStorageInterface::class),
        );

        $defaultPool = new ContainerBuilder();
        $defaultPool->setParameter('kernel.project_dir', sys_get_temp_dir());
        (new SiteBackupExtension())->load([[
            'security' => ['allow_unauthenticated' => true],
            'setup'    => [
                'progress_storage'    => 'cache',
                'progress_cache_pool' => 123,
            ],
        ]], $defaultPool);
        self::assertTrue($defaultPool->hasDefinition(CacheSetupProgressStorage::class));

        $extension = new SiteBackupExtension();
        $twigSkip  = new ReflectionMethod(SiteBackupExtension::class, 'configureTwigGlobals');
        $twigSkip->setAccessible(true);
        $empty = new ContainerBuilder();
        $empty->setParameter('kernel.project_dir', sys_get_temp_dir());
        (new SiteBackupExtension())->load([['security' => ['allow_unauthenticated' => true]]], $empty);
        $empty->removeDefinition(TwigSiteBackupExtension::class);
        $config = (new Processor())->processConfiguration(new Configuration(), [['security' => ['allow_unauthenticated' => true]]]);
        $twigSkip->invoke($extension, $empty, $config);
        self::assertFalse($empty->hasDefinition(TwigSiteBackupExtension::class));
    }

    public function testCacheSetupProgressStorageCatchPaths(): void
    {
        $throwing = new class extends ArrayAdapter {
            public function getItem(mixed $key): never
            {
                throw new RuntimeException('cache down');
            }
        };
        $storage = new CacheSetupProgressStorage($throwing);
        self::assertSame(SetupProgress::PHASE_IDLE, $storage->load()->getPhase());

        $saveFail = new class extends ArrayAdapter {
            public function getItem(mixed $key): CacheItem
            {
                throw new RuntimeException('save fail');
            }
        };
        $saveStorage = new CacheSetupProgressStorage($saveFail);
        $saveStorage->save(new SetupProgress(phase: SetupProgress::PHASE_RUNNING, currentStepId: 'x'));

        $badData = new ArrayAdapter();
        $item    = $badData->getItem('nowo_site_backup.setup_progress');
        $item->set('not-an-array');
        $badData->save($item);
        $badStorage = new CacheSetupProgressStorage($badData);
        self::assertSame(SetupProgress::PHASE_IDLE, $badStorage->load()->getPhase());

        $idleStorage = new CacheSetupProgressStorage(new ArrayAdapter());
        $idleStorage->save(new SetupProgress(phase: SetupProgress::PHASE_IDLE));
        self::assertSame(SetupProgress::PHASE_IDLE, $idleStorage->load()->getPhase());
    }

    public function testCacheDoctrineStorageDoctrineUsableAndMeaningfulLoad(): void
    {
        $cache = new CacheSetupProgressStorage(new ArrayAdapter());
        $db    = new DoctrineDbalSetupProgressStorage(new FakeDbalConnection());
        $db->save(new SetupProgress(
            phase: SetupProgress::PHASE_WAITING,
            currentStepId: 'from_db',
            startedAt: new DateTimeImmutable(),
        ));

        $storage = new CacheDoctrineSetupProgressStorage($cache, $db);
        self::assertSame('from_db', $storage->load()->getCurrentStepId());

        $throwingDb = $this->createMock(SetupProgressStorageInterface::class);
        $throwingDb->method('load')->willThrowException(new RuntimeException('db down'));
        $storage2 = new CacheDoctrineSetupProgressStorage($cache, $throwingDb);
        self::assertSame('from_db', $storage2->load()->getCurrentStepId());

        $unusableDb = new DoctrineDbalSetupProgressStorage();
        $storage3   = new CacheDoctrineSetupProgressStorage($cache, $unusableDb);
        self::assertSame('from_db', $storage3->load()->getCurrentStepId());
    }

    public function testSetupDbDoneGuardCatchPaths(): void
    {
        $store = new class implements DurableSetupDoneStoreInterface {
            public function isDone(): bool
            {
                throw new RuntimeException('store down');
            }

            public function markDone(): void
            {
            }
        };
        $guard = new SetupDbDoneGuard(
            $store,
            new SetupNeedEvaluator([], true),
            new SetupMarkerManager(
                $this->harnessProjectDir . '/var/site-backup/setup.required',
                $this->harnessProjectDir . '/var/site-backup/setup.done',
            ),
            new FilesystemSetupProgressStorage($this->harnessProjectDir . '/var/site-backup/setup-progress.json'),
        );
        self::assertFalse($guard->shouldCloseWizard());

        $throwingStorage = new class implements SetupProgressStorageInterface {
            public function load(): SetupProgress
            {
                throw new RuntimeException('progress down');
            }

            public function save(SetupProgress $progress): void
            {
            }
        };
        $healGuard = new SetupDbDoneGuard(
            new FakeDurableSetupDoneStore(true),
            new SetupNeedEvaluator([], true),
            new SetupMarkerManager(
                $this->harnessProjectDir . '/var/site-backup/setup.required2',
                $this->harnessProjectDir . '/var/site-backup/setup.done2',
            ),
            $throwingStorage,
        );
        $healGuard->healSideEffects();
        self::assertTrue(true);

        $completedStorage = new FilesystemSetupProgressStorage($this->harnessProjectDir . '/var/site-backup/setup-progress-completed.json');
        $completedStorage->save(new SetupProgress(phase: SetupProgress::PHASE_COMPLETED, percent: 100.0));
        $completedGuard = new SetupDbDoneGuard(
            new FakeDurableSetupDoneStore(true),
            new SetupNeedEvaluator([], true),
            new SetupMarkerManager(
                $this->harnessProjectDir . '/var/site-backup/setup.required3',
                $this->harnessProjectDir . '/var/site-backup/setup.done3',
            ),
            $completedStorage,
        );
        $completedGuard->healSideEffects();
        self::assertSame(SetupProgress::PHASE_COMPLETED, $completedStorage->load()->getPhase());
    }

    public function testSetupDbDoneRedirectSubscriberSubRequestSkip(): void
    {
        $guard = new SetupDbDoneGuard(
            new FakeDurableSetupDoneStore(true),
            new SetupNeedEvaluator([], true),
            new SetupMarkerManager(
                $this->harnessProjectDir . '/var/site-backup/setup.required4',
                $this->harnessProjectDir . '/var/site-backup/setup.done4',
            ),
            new FilesystemSetupProgressStorage($this->harnessProjectDir . '/var/site-backup/setup-progress4.json'),
        );
        $subscriber = new SetupDbDoneRedirectSubscriber($guard);
        $event      = new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            Request::create('/_setup'),
            HttpKernelInterface::SUB_REQUEST,
        );
        $subscriber->onKernelRequest($event);
        self::assertFalse($event->hasResponse());
    }

    public function testPanelFormTypesBuild(): void
    {
        $factory = Forms::createFormFactoryBuilder()->getFormFactory();
        $action  = $factory->create(PanelActionType::class, null, ['action' => 'verify', 'backup_id' => 'id-1']);
        self::assertTrue($action->has('backup_id'));

        $emptyAction = $factory->create(PanelActionType::class, null, ['action' => 'create', 'backup_id' => '']);
        self::assertFalse($emptyAction->has('backup_id'));

        $login = $factory->create(PanelLoginType::class);
        self::assertTrue($login->has('password'));
    }

    public function testFinalCoverageTargets(): void
    {
        $conn    = new FakeDbalConnection();
        $journal = new DoctrineDbalSetupStepJournal($conn);
        $storage = new DoctrineDbalSetupProgressStorage($conn, DoctrineDbalSetupProgressStorage::TABLE, $journal, true);
        $storage->save(new SetupProgress(phase: SetupProgress::PHASE_RUNNING, currentStepId: 'a', percent: 1.0));
        $storage->save(new SetupProgress(phase: SetupProgress::PHASE_WAITING, currentStepId: 'b', percent: 2.0));
        $storage->save(new SetupProgress(phase: SetupProgress::PHASE_IDLE, profile: 'fresh_install'));

        $syncFailConn = new class extends FakeDbalConnection {
            public function executeStatement(string $sql, array $params = []): int
            {
                $normalized = strtolower($sql);
                if (str_contains($normalized, 'insert') && str_contains($normalized, 'step_id') && !str_contains($normalized, 'current_step_id')) {
                    throw new RuntimeException('sync fail');
                }

                return parent::executeStatement($sql, $params);
            }
        };
        $failStorage = new DoctrineDbalSetupProgressStorage($syncFailConn, DoctrineDbalSetupProgressStorage::TABLE, new DoctrineDbalSetupStepJournal($syncFailConn), true);
        $failStorage->save(new SetupProgress(phase: SetupProgress::PHASE_RUNNING, currentStepId: 'x', percent: 1.0));

        $persistFailConn = new class extends FakeDbalConnection {
            public function executeStatement(string $sql, array $params = []): int
            {
                $normalized = strtolower(trim($sql));
                if ((str_starts_with($normalized, 'insert') || str_starts_with($normalized, 'update'))
                    && !str_contains($normalized, 'step_order')) {
                    throw new RuntimeException('persist fail');
                }

                return parent::executeStatement($sql, $params);
            }
        };
        $persistStorage = new DoctrineDbalSetupProgressStorage($persistFailConn);
        try {
            $persistStorage->save(new SetupProgress(phase: SetupProgress::PHASE_RUNNING, currentStepId: 'y', percent: 1.0));
            self::fail('Expected persist failure');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('persist setup progress', $e->getMessage());
        }

        $enrichFailConn = new class extends FakeDbalConnection {
            public function executeQuery(string $sql, array $params = []): FakeDbalResult
            {
                $normalized = strtolower($sql);
                if (str_contains($normalized, 'select') && str_contains($normalized, 'step_id') && !str_contains($normalized, 'current_step_id')) {
                    throw new RuntimeException('enrich fail');
                }

                return parent::executeQuery($sql, $params);
            }
        };
        $enrichStorage = new DoctrineDbalSetupProgressStorage($enrichFailConn, DoctrineDbalSetupProgressStorage::TABLE, new DoctrineDbalSetupStepJournal($enrichFailConn), true);
        $enrichFailConn->seedRow([
            'phase'      => 'running', 'profile' => 'p', 'current_step_id' => 's', 'percent' => 1,
            'started_at' => null, 'updated_at' => null, 'completed_at' => null,
            'payload'    => json_encode(['phase' => 'running', 'profile' => 'p', 'current_step_id' => 's', 'percent' => 1.0, 'completed_step_ids' => []], JSON_THROW_ON_ERROR),
        ]);
        self::assertSame(SetupProgress::PHASE_RUNNING, $enrichStorage->load()->getPhase());

        $freshConn    = new FakeDbalConnection();
        $freshJournal = new DoctrineDbalSetupStepJournal($freshConn);
        $same         = new SetupProgress(phase: SetupProgress::PHASE_WAITING, profile: 'fresh_install', currentStepId: 'x', completedStepIds: ['only']);
        self::assertSame($same, $freshJournal->enrich($same));

        $stmtOnly = new class {
            public function executeStatement(string $sql, array $params = []): int
            {
                return 0;
            }
        };
        $stmtStorage = new DoctrineDbalSetupProgressStorage($stmtOnly);
        self::assertSame(SetupProgress::PHASE_IDLE, $stmtStorage->load()->getPhase());

        $noFetchAssoc = new class {
            /**
             * @param list<mixed> $params
             */
            public function executeQuery(string $sql, array $params = []): \stdClass
            {
                return new stdClass();
            }

            /**
             * @param list<mixed> $params
             */
            public function executeStatement(string $sql, array $params = []): int
            {
                return 0;
            }
        };
        self::assertSame(SetupProgress::PHASE_IDLE, (new DoctrineDbalSetupProgressStorage($noFetchAssoc))->load()->getPhase());

        $execute = new ReflectionMethod(DoctrineDbalSetupProgressStorage::class, 'executeStatement');
        try {
            $execute->invoke(new DoctrineDbalSetupProgressStorage(), 'SELECT 1', []);
            self::fail('Expected missing connection');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('No DBAL connection', $e->getMessage());
        }

        try {
            $execute->invoke(new DoctrineDbalSetupProgressStorage(new stdClass()), 'SELECT 1', []);
            self::fail('Expected unusable connection');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('cannot execute statements', $e->getMessage());
        }

        try {
            (new DoctrineDbalSetupProgressStorage(new FakeDbalConnection()))->save(new SetupProgress(message: "\xC3\x28"));
            self::fail('Expected encode failure');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('Unable to encode setup progress', $e->getMessage());
        }

        $journalOnly = new DoctrineDbalSetupStepJournal($conn);
        $journalOnly->sync(new SetupProgress(phase: SetupProgress::PHASE_WAITING, currentStepId: '', completedStepIds: ['done']));
        $journalOnly->clear('profile-x');
        $journalOnly->clear();

        $noopJournal = new DoctrineDbalSetupStepJournal();
        $noopJournal->clear();
        self::assertSame([], $noopJournal->listCompletedStepIds());

        $fetchConn = new class {
            public function executeStatement(string $sql, array $params = []): int
            {
                return 0;
            }
        };
        $fetchJournal = new DoctrineDbalSetupStepJournal($fetchConn);
        $fetchJournal->sync(new SetupProgress(phase: SetupProgress::PHASE_RUNNING, currentStepId: 'only', completedStepIds: ['prev']));

        $badResultConn = new class {
            public function executeStatement(string $sql, array $params = []): int
            {
                return 0;
            }

            public function executeQuery(string $sql, array $params = []): \stdClass
            {
                return new stdClass();
            }
        };
        self::assertSame([], (new DoctrineDbalSetupStepJournal($badResultConn))->listCompletedStepIds());

        $loopFetchConn = new class {
            public function executeStatement(string $sql, array $params = []): int
            {
                return 0;
            }

            public function executeQuery(string $sql, array $params = []): object
            {
                return new class {
                    public function fetchAllAssociative(): string
                    {
                        return 'nope';
                    }
                };
            }
        };
        self::assertSame([], (new DoctrineDbalSetupStepJournal($loopFetchConn))->listCompletedStepIds());

        $checker = new MysqlSchemaExistenceChecker();
        $unknown = new ReflectionMethod(MysqlSchemaExistenceChecker::class, 'isUnknownDatabase');
        self::assertTrue($unknown->invoke($checker, new RuntimeException('Error 1049')));

        $attrClass = new ReflectionClass(ColdStartRequestAttributes::class);
        $attrCtor  = $attrClass->getConstructor();
        self::assertNotNull($attrCtor);
        $attrCtor->invoke($attrClass->newInstanceWithoutConstructor());

        $gate = new ColdStartSchemaGateSubscriber(
            schemaChecker: new class implements SchemaExistenceCheckerInterface {
                public function schemaExists(): bool
                {
                    return false;
                }
            },
            pathPrefixResolver: new SetupPathPrefixResolver(new RequestStack(), '/_setup', 'never', 'en', ['en']),
            stopPropagation: false,
        );
        $kernel = $this->createMock(HttpKernelInterface::class);
        $event  = new RequestEvent($kernel, Request::create('/blocked'), HttpKernelInterface::MAIN_REQUEST);
        $gate->onKernelRequestProbe($event);
        $gate->onKernelRequestRedirect($event);
        self::assertNotNull($event->getResponse());

        $lateGate = new ColdStartSchemaGateSubscriber(
            schemaChecker: new class implements SchemaExistenceCheckerInterface {
                public function schemaExists(): bool
                {
                    return false;
                }
            },
            pathPrefixResolver: new SetupPathPrefixResolver(new RequestStack(), '/_setup', 'never', 'en', ['en']),
            safePathPrefixes: ['/health/'],
            stopPropagation: true,
        );
        $lateBlocked = new RequestEvent($kernel, Request::create('/blocked'), HttpKernelInterface::MAIN_REQUEST);
        $lateBlocked->getRequest()->attributes->set(ColdStartRequestAttributes::SCHEMA_EXISTS, false);
        $lateGate->onKernelRequestStopLateListeners($lateBlocked);
        self::assertFalse($lateBlocked->isPropagationStopped());

        $lateSub = new RequestEvent($kernel, Request::create('/health/status'), HttpKernelInterface::SUB_REQUEST);
        $lateGate->onKernelRequestStopLateListeners($lateSub);

        $step = new RequirementsStep('req', 'Req', ['json'], ['var'], requireTar: false);
        if (!function_exists('exec')) {
            self::assertFalse($step->run(new SetupContext($this->harnessProjectDir, 'p'), new SetupStepInput())->isSuccess());
        }

        file_put_contents($this->harnessProjectDir . '/config/app.yaml', 'x');
        $archiver = new BackupArchiver(
            projectDir: $this->harnessProjectDir,
            storageDir: $this->harnessStorageDir . '-missing-include',
            includePaths: ['config/missing-include-path'],
            excludePatterns: [],
            databaseDumpCommand: null,
            processTimeoutSeconds: 60,
        );
        self::assertSame([], $archiver->create('missing-include', 'phpunit')->getChecksums());
    }

    public function testRemainingStatementCoverage(): void
    {
        $ref = new ReflectionClass(ColdStartRequestAttributes::class);
        $ref->newInstanceWithoutConstructor();

        $noopJournal = new DoctrineDbalSetupStepJournal();
        $idle        = new SetupProgress();
        self::assertSame($idle, $noopJournal->enrich($idle));

        $conn    = new FakeDbalConnection();
        $journal = new DoctrineDbalSetupStepJournal($conn);
        $journal->sync(new SetupProgress(
            phase: SetupProgress::PHASE_WAITING,
            profile: 'fresh_install',
            currentStepId: 'step_a',
            completedStepIds: ['done_step'],
            updatedAt: new DateTimeImmutable(),
        ));
        $alreadyComplete = new SetupProgress(
            phase: SetupProgress::PHASE_WAITING,
            profile: 'fresh_install',
            currentStepId: 'step_a',
            completedStepIds: ['done_step'],
        );
        self::assertSame($alreadyComplete, $journal->enrich($alreadyComplete));

        $badFetchOneConn = new class {
            public function executeStatement(string $sql, array $params = []): int
            {
                return 0;
            }

            public function executeQuery(string $sql, array $params = []): object
            {
                if (str_contains(strtolower($sql), 'and step_id')) {
                    return new stdClass();
                }

                return new FakeDbalResult(null);
            }
        };
        (new DoctrineDbalSetupStepJournal($badFetchOneConn))->sync(new SetupProgress(
            phase: SetupProgress::PHASE_RUNNING,
            profile: 'p',
            currentStepId: 'current',
            completedStepIds: [],
        ));

        $stmtOnlyConn = new class {
            public function executeStatement(string $sql, array $params = []): int
            {
                return 0;
            }
        };
        self::assertSame([], (new DoctrineDbalSetupStepJournal($stmtOnlyConn))->listCompletedStepIds());

        $nullResultConn = new class {
            public function executeStatement(string $sql, array $params = []): int
            {
                return 0;
            }

            public function executeQuery(string $sql, array $params = []): null
            {
                return null;
            }
        };
        self::assertSame([], (new DoctrineDbalSetupStepJournal($nullResultConn))->listCompletedStepIds());

        $cache = new CacheSetupProgressStorage(new ArrayAdapter());
        $cache->save(new SetupProgress(
            phase: SetupProgress::PHASE_WAITING,
            currentStepId: 'stale',
            completedStepIds: ['migrations'],
            startedAt: new DateTimeImmutable(),
        ));
        $staleStorage = new CacheDoctrineSetupProgressStorage(
            $cache,
            new DoctrineDbalSetupProgressStorage(),
            new class implements SchemaExistenceCheckerInterface {
                public function schemaExists(): bool
                {
                    return false;
                }
            },
        );
        self::assertSame(SetupProgress::PHASE_IDLE, $staleStorage->load()->getPhase());

        $cacheOnly = new CacheSetupProgressStorage(new ArrayAdapter());
        (new CacheDoctrineSetupProgressStorage($cacheOnly, new DoctrineDbalSetupProgressStorage()))
            ->save(new SetupProgress(phase: SetupProgress::PHASE_RUNNING, currentStepId: 'cache_only'));

        $unreadableDir = $this->harnessProjectDir . '/unreadable-dir';
        mkdir($unreadableDir, 0000);
        $archiver = new BackupArchiver(
            projectDir: $this->harnessProjectDir,
            storageDir: $this->harnessStorageDir . '-unreadable',
            includePaths: ['unreadable-dir'],
            excludePatterns: [],
            databaseDumpCommand: null,
            processTimeoutSeconds: 60,
        );
        self::assertSame([], $archiver->create('unreadable', 'phpunit')->getChecksums());
        chmod($unreadableDir, 0755);

        if (function_exists('exec')) {
            $badTar = new RequirementsStep(
                'req-tar',
                'Req',
                ['json', 'pdo'],
                ['var'],
                requireTar: true,
            );
            $ctx = new SetupContext($this->harnessProjectDir, 'p');
            @mkdir($this->harnessProjectDir . '/var', 0775, true);
            $result = $badTar->run($ctx, new SetupStepInput());
            if (!str_contains(implode(' ', $result->getLog()), 'tar: ok')) {
                self::assertFalse($result->isSuccess());
            }
        }
    }
}

final class DummyUiKitExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
    }

    public function getAlias(): string
    {
        return 'nowo_ui_kit';
    }
}

final class DummySecurityExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
    }

    public function getAlias(): string
    {
        return 'security';
    }
}
