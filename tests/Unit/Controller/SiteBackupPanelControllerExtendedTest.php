<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Controller;

use DateTimeImmutable;
use Nowo\SiteBackupBundle\Controller\SiteBackupPanelController;
use Nowo\SiteBackupBundle\Model\BackupHistoryEntry;
use Nowo\SiteBackupBundle\Model\RestoreProgress;
use Nowo\SiteBackupBundle\Restore\RestoreOrchestrator;
use Nowo\SiteBackupBundle\Security\ActorResolverInterface;
use Nowo\SiteBackupBundle\Security\PanelActorResolverInterface;
use Nowo\SiteBackupBundle\Security\PanelRequestContext;
use Nowo\SiteBackupBundle\Security\PasswordSiteBackupAccessGate;
use Nowo\SiteBackupBundle\Security\SiteBackupAccessCheckerInterface;
use Nowo\SiteBackupBundle\Service\SiteBackupManager;
use Nowo\SiteBackupBundle\Service\SiteBackupManagerInterface;
use Nowo\SiteBackupBundle\Storage\FilesystemBackupHistoryStorage;
use Nowo\SiteBackupBundle\Storage\FilesystemRestoreProgressStorage;
use Nowo\SiteBackupBundle\Tests\Unit\CreatesSiteBackupTestHarness;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

use function array_map;
use function str_repeat;

use const PASSWORD_DEFAULT;

final class SiteBackupPanelControllerExtendedTest extends TestCase
{
    use CreatesFormFactory;
    use CreatesSiteBackupTestHarness;

    protected function setUp(): void
    {
        $this->initHarness();
    }

    protected function tearDown(): void
    {
        $this->destroyHarness();
    }

    private function controller(): SiteBackupPanelController
    {
        $manager = $this->createManager();
        $gate    = new PasswordSiteBackupAccessGate(password_hash('secret', PASSWORD_DEFAULT), true);
        $twig    = $this->createMock(Environment::class);
        $twig->method('render')->willReturn('html');
        $csrf = $this->createMock(CsrfTokenManagerInterface::class);
        $csrf->method('getToken')->willReturn(new CsrfToken('id', 'token'));
        $csrf->method('isTokenValid')->willReturn(true);

        return new SiteBackupPanelController(
            $manager,
            $gate,
            $this->createFormFactory($csrf),
            $twig,
            ['panel_index' => 'index', 'panel_login' => 'login', 'panel_history' => 'history'],
            '/_site_backup',
            $csrf,
        );
    }

    private function authenticate(SiteBackupPanelController $controller, Session $session): void
    {
        $login = Request::create('/', 'POST', ['action' => 'login', 'password' => 'secret', '_csrf_token' => 'token']);
        $login->setSession($session);
        $controller->index($login);
    }

    public function testProgressJsonReportsAbandonedRestoreAsInactiveAndTranslatesTheMessage(): void
    {
        $storage = new FilesystemRestoreProgressStorage($this->harnessProgressFile);
        $stale   = new DateTimeImmutable('-1 hour');
        $storage->save(new RestoreProgress(
            active: true,
            phase: RestoreProgress::PHASE_APPLYING,
            percent: 60.0,
            message: RestoreOrchestrator::MESSAGE_APPLYING,
            startedAt: $stale,
            updatedAt: $stale,
        ));

        $manager = new SiteBackupManager(
            $this->createArchiver(),
            new RestoreOrchestrator(
                projectDir: $this->harnessProjectDir,
                archiver: $this->createArchiver(),
                progressStorage: $storage,
                protectedRelativePaths: [],
                staleAfterSeconds: 60,
            ),
            new FilesystemBackupHistoryStorage($this->harnessHistoryFile),
        );
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id === RestoreOrchestrator::MESSAGE_APPLYING ? 'Aplicando los archivos de forma segura…' : $id);

        $controller = new SiteBackupPanelController(
            $manager,
            new PasswordSiteBackupAccessGate(null, false),
            $this->createFormFactory(),
            $this->createMock(Environment::class),
            ['panel_index' => 'index', 'panel_login' => 'login', 'panel_history' => 'history'],
            '/_site_backup',
            translator: $translator,
        );

        $data = (array) json_decode((string) $controller->progress()->getContent(), true);
        self::assertFalse($data['active']);
        self::assertSame('applying', $data['phase']);
        self::assertSame('Aplicando los archivos de forma segura…', $data['message']);

        // Fresh progress stays active.
        $storage->save(new RestoreProgress(active: true, phase: RestoreProgress::PHASE_APPLYING, percent: 60.0, updatedAt: new DateTimeImmutable()));
        $data = (array) json_decode((string) $controller->progress()->getContent(), true);
        self::assertTrue($data['active']);
        self::assertNull($data['message']);
    }

    public function testLoginFailureInvalidPasswordAndCsrf(): void
    {
        $controller = $this->controller();
        new Session(new MockArraySessionStorage());
        $badLogin = Request::create('/', 'POST', ['action' => 'login', 'password' => 'wrong', '_csrf_token' => 'token']);
        $badLogin->setSession(new Session(new MockArraySessionStorage()));
        self::assertSame(401, $controller->index($badLogin)->getStatusCode());

        $csrf = $this->createMock(CsrfTokenManagerInterface::class);
        $csrf->method('getToken')->willReturn(new CsrfToken('id', 'token'));
        $csrf->method('isTokenValid')->willReturn(false);
        $controllerCsrf = new SiteBackupPanelController(
            $this->createManager(),
            new PasswordSiteBackupAccessGate(password_hash('secret', PASSWORD_DEFAULT), true),
            $this->createFormFactory($csrf),
            $this->createMock(Environment::class),
            ['panel_index' => 'index', 'panel_login' => 'login', 'panel_history' => 'history'],
            '/_site_backup',
            $csrf,
        );
        $badCsrfLogin = Request::create('/', 'POST', ['action' => 'login', 'password' => 'secret', '_csrf_token' => 'bad']);
        $badCsrfLogin->setSession(new Session(new MockArraySessionStorage()));
        self::assertSame(401, $controllerCsrf->index($badCsrfLogin)->getStatusCode());
    }

    public function testVerifyFailureAndUnauthenticatedHistory(): void
    {
        $manager  = $this->createManager();
        $artifact = $manager->createBackup('v', 'cli');
        file_put_contents($artifact->getAbsolutePath(), 'corrupt');

        $controller = $this->controller();
        $session    = new Session(new MockArraySessionStorage());
        self::assertSame(302, $controller->history(Request::create('/history'))->getStatusCode());
        $this->authenticate($controller, $session);

        $verify = Request::create('/', 'POST', [
            'action'      => 'verify',
            'backup_id'   => $artifact->getId(),
            '_csrf_token' => 'token',
        ]);
        $verify->setSession($session);
        self::assertSame(200, $controller->index($verify)->getStatusCode());
    }

    public function testCreateBackupFailure(): void
    {
        // A regular file where the storage dir should be: the archiver cannot write there for any uid
        // (chmod 0444 is ignored by root, which made this test environment-dependent).
        $this->harnessFs->remove($this->harnessStorageDir);
        $this->harnessFs->dumpFile($this->harnessStorageDir, 'not a directory');
        $session = new Session(new MockArraySessionStorage());
        $csrf    = $this->createMock(CsrfTokenManagerInterface::class);
        $csrf->method('getToken')->willReturn(new CsrfToken('id', 'token'));
        $csrf->method('isTokenValid')->willReturn(true);
        $twig = $this->createMock(Environment::class);
        $twig->method('render')->willReturn('html');
        $controller = new SiteBackupPanelController(
            $this->createManager(),
            new PasswordSiteBackupAccessGate(null, false),
            $this->createFormFactory($csrf),
            $twig,
            ['panel_index' => 'index', 'panel_login' => 'login', 'panel_history' => 'history'],
            '/_site_backup',
            $csrf,
        );
        $request = Request::create('/', 'POST', ['action' => 'create', '_csrf_token' => 'token']);
        $request->setSession($session);
        // A failed create goes back to the create form with the error (422), not to the index.
        self::assertSame(422, $controller->index($request)->getStatusCode());
    }

    public function testRestoreFailureShowsOnlyTheRedactedMessage(): void
    {
        $manager = $this->createMock(SiteBackupManagerInterface::class);
        $manager->method('restore')->willThrowException(new RuntimeException('SQLSTATE[08006] could not connect to postgresql://app:REDACTED-TEST@pg/app'));
        $manager->method('listBackups')->willReturn([]);
        $manager->method('getRestoreProgress')->willReturn(new RestoreProgress());
        $csrf = $this->createMock(CsrfTokenManagerInterface::class);
        $csrf->method('getToken')->willReturn(new CsrfToken('id', 'token'));
        $csrf->method('isTokenValid')->willReturn(true);
        /** @var list<array<string, mixed>> $contexts */
        $contexts = [];
        $twig     = $this->createMock(Environment::class);
        $twig->method('render')->willReturnCallback(static function (string $template, array $context) use (&$contexts): string {
            $contexts[] = $context;

            return 'html';
        });
        $controller = new SiteBackupPanelController(
            $manager,
            new PasswordSiteBackupAccessGate(null, false),
            $this->createFormFactory($csrf),
            $twig,
            ['panel_index' => 'index', 'panel_login' => 'login', 'panel_history' => 'history'],
            '/_site_backup',
            $csrf,
        );
        $request = Request::create('/', 'POST', ['action' => 'restore', 'backup_id' => 'b1', '_csrf_token' => 'token']);
        $request->setSession(new Session(new MockArraySessionStorage()));

        self::assertSame(200, $controller->index($request)->getStatusCode());
        self::assertNotSame([], $contexts);
        self::assertSame(['error' => ['SQLSTATE[08006] could not connect to postgresql://app:***@pg/app']], $contexts[0]['messages']);
    }

    public function testIndexReturnsForbiddenWhenRoleAccessDenied(): void
    {
        $checker = $this->createMock(SiteBackupAccessCheckerInterface::class);
        $checker->method('canAccess')->willReturn(false);

        $controller = new SiteBackupPanelController(
            $this->createManager(),
            new PasswordSiteBackupAccessGate(null, false),
            $this->createFormFactory(),
            $this->createMock(Environment::class),
            ['panel_index' => 'index', 'panel_login' => 'login', 'panel_history' => 'history'],
            '/_site_backup',
            null,
            $checker,
            false,
        );

        self::assertSame(403, $controller->index(Request::create('/'))->getStatusCode());
        self::assertSame(403, $controller->history(Request::create('/history'))->getStatusCode());
    }

    public function testIndexReturnsForbiddenWhenAccessCheckerMissingAndUnauthenticatedDisallowed(): void
    {
        $controller = new SiteBackupPanelController(
            $this->createManager(),
            new PasswordSiteBackupAccessGate(null, false),
            $this->createFormFactory(),
            $this->createMock(Environment::class),
            ['panel_index' => 'index', 'panel_login' => 'login', 'panel_history' => 'history'],
            '/_site_backup',
            null,
            null,
            false,
        );

        self::assertSame(403, $controller->index(Request::create('/'))->getStatusCode());
    }

    public function testIndexAllowsWhenAccessCheckerGrants(): void
    {
        $checker = $this->createMock(SiteBackupAccessCheckerInterface::class);
        $checker->method('canAccess')->willReturn(true);
        $twig = $this->createMock(Environment::class);
        $twig->method('render')->willReturn('html');

        $controller = new SiteBackupPanelController(
            $this->createManager(),
            new PasswordSiteBackupAccessGate(null, false),
            $this->createFormFactory(),
            $twig,
            ['panel_index' => 'index', 'panel_login' => 'login', 'panel_history' => 'history'],
            '/_site_backup',
            null,
            $checker,
            false,
        );

        self::assertSame(200, $controller->index(Request::create('/'))->getStatusCode());
    }

    public function testPanelWritesRecordPanelActorByDefault(): void
    {
        $manager = $this->createManager();
        $this->openController($manager)->index($this->post(['action' => 'create', '_csrf_token' => 'token']));

        $backups = $manager->listBackups();
        self::assertCount(1, $backups);
        self::assertSame('panel', $backups[0]->getCreatedBy());
        self::assertSame('panel', $manager->history()[0]->getActor());
    }

    public function testPanelWritesUseTheActorResolver(): void
    {
        $manager  = $this->createManager();
        $resolver = new class implements ActorResolverInterface {
            public function resolveActor(Request $request): string
            {
                return 'user-42';
            }
        };
        $controller = $this->openController($manager, $resolver);

        $controller->index($this->post(['action' => 'create', '_csrf_token' => 'token']));
        $id = $manager->listBackups()[0]->getId();
        self::assertSame('user-42', $manager->listBackups()[0]->getCreatedBy());

        $controller->index($this->post(['action' => 'delete', 'backup_id' => $id, '_csrf_token' => 'token']));

        self::assertSame([], $manager->listBackups());
        $actors = array_map(static fn (BackupHistoryEntry $entry): ?string => $entry->getActor(), $manager->history());
        self::assertSame(['user-42', 'user-42'], $actors);
    }

    public function testHostResolverOutputIsNormalisedIntoAValidCreatedBy(): void
    {
        $manager  = $this->createManager();
        $resolver = new class implements ActorResolverInterface {
            public function resolveActor(Request $request): string
            {
                return "evil\nname" . str_repeat('x', 200);
            }
        };

        $this->openController($manager, $resolver)->index($this->post(['action' => 'create', '_csrf_token' => 'token']));

        $backups = $manager->listBackups();
        self::assertCount(1, $backups);
        self::assertSame('evilname' . str_repeat('x', 120), $backups[0]->getCreatedBy());
    }

    /**
     * The framework-free resolver port records the same actors as the deprecated one (history, created-by), from the
     * token user identifier.
     */
    public function testFrameworkFreeResolverRecordsTheSameActors(): void
    {
        $storage = new TokenStorage();
        $storage->setToken(new UsernamePasswordToken(new InMemoryUser("ops\nlead", null, ['ROLE_ADMIN']), 'main', ['ROLE_ADMIN']));
        $legacy = new class($storage) implements ActorResolverInterface {
            public function __construct(private readonly TokenStorage $storage)
            {
            }

            public function resolveActor(Request $request): ?string
            {
                return $this->storage->getToken()?->getUserIdentifier();
            }
        };
        $port = new class implements PanelActorResolverInterface {
            public function resolveActor(PanelRequestContext $context): ?string
            {
                return $context->userIdentifier;
            }
        };

        $actors = [];
        foreach ([$legacy, $port] as $resolver) {
            $manager = $this->createManager();
            $this->openController($manager, $resolver, $storage)->index($this->post(['action' => 'create', '_csrf_token' => 'token']));
            $actors[] = [$manager->listBackups()[0]->getCreatedBy(), $manager->history()[0]->getActor()];
        }

        self::assertSame($actors[0], $actors[1]);
        self::assertSame(['opslead', 'opslead'], $actors[1]);
    }

    public function testResolverReturningNullRecordsNoActor(): void
    {
        $manager = $this->createManager();
        $port    = new class implements PanelActorResolverInterface {
            public function resolveActor(PanelRequestContext $context): ?string
            {
                return null;
            }
        };

        $this->openController($manager, $port)->index($this->post(['action' => 'create', '_csrf_token' => 'token']));

        self::assertNull($manager->history()[0]->getActor());
    }

    public function testAccessCheckerReceivesTheTokenUser(): void
    {
        $user    = new InMemoryUser('alice', null, ['ROLE_ADMIN']);
        $storage = new TokenStorage();
        $storage->setToken(new UsernamePasswordToken($user, 'main', ['ROLE_ADMIN']));
        $checker = $this->createMock(SiteBackupAccessCheckerInterface::class);
        $checker->expects(self::once())->method('canAccess')->with(self::identicalTo($user))->willReturn(false);

        $controller = new SiteBackupPanelController(
            $this->createManager(),
            new PasswordSiteBackupAccessGate(null, false),
            $this->createFormFactory(),
            $this->createMock(Environment::class),
            ['panel_index' => 'index', 'panel_login' => 'login', 'panel_history' => 'history'],
            '/_site_backup',
            null,
            $checker,
            false,
            tokenStorage: $storage,
        );

        self::assertSame(403, $controller->index(Request::create('/'))->getStatusCode());
    }

    public function testAccessCheckerReceivesNullWithoutAToken(): void
    {
        $checker = $this->createMock(SiteBackupAccessCheckerInterface::class);
        $checker->expects(self::once())->method('canAccess')->with(null)->willReturn(false);

        $controller = new SiteBackupPanelController(
            $this->createManager(),
            new PasswordSiteBackupAccessGate(null, false),
            $this->createFormFactory(),
            $this->createMock(Environment::class),
            ['panel_index' => 'index', 'panel_login' => 'login', 'panel_history' => 'history'],
            '/_site_backup',
            null,
            $checker,
            false,
            tokenStorage: new TokenStorage(),
        );

        self::assertSame(403, $controller->index(Request::create('/'))->getStatusCode());
    }

    /**
     * Panel without password gate, valid CSRF.
     */
    private function openController(SiteBackupManager $manager, ActorResolverInterface|PanelActorResolverInterface|null $resolver = null, ?TokenStorage $tokenStorage = null): SiteBackupPanelController
    {
        $csrf = $this->createMock(CsrfTokenManagerInterface::class);
        $csrf->method('getToken')->willReturn(new CsrfToken('id', 'token'));
        $csrf->method('isTokenValid')->willReturn(true);
        $twig = $this->createMock(Environment::class);
        $twig->method('render')->willReturn('html');

        return new SiteBackupPanelController(
            $manager,
            new PasswordSiteBackupAccessGate(null, false),
            $this->createFormFactory($csrf),
            $twig,
            ['panel_index' => 'index', 'panel_login' => 'login', 'panel_history' => 'history'],
            '/_site_backup',
            $csrf,
            actorResolver: $resolver,
            tokenStorage: $tokenStorage,
        );
    }

    /**
     * @param array<string, string> $data
     */
    private function post(array $data): Request
    {
        $request = Request::create('/', 'POST', $data);
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $request;
    }
}
