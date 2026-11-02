<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Integration\SecAudit;

use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

use function bin2hex;
use function json_encode;
use function preg_match;
use function random_bytes;

/**
 * REQ-SEC-004 Appendix CG regression suite (setup access default-deny) on a real kernel that
 * keeps the bundle defaults: F1 (anonymous, self-starting setup wizard) and I1 (setup.done closes it whatever the
 * progress file says).
 */
final class SetupWizardDefaultDenyTest extends TestCase
{
    private const STATIC_TOKEN = 'long-random-setup-token-0123456789';

    /** Minimal first-install profile: no console steps, so nothing is spawned by the test. */
    private const PROFILE = ['setup' => [
        'admin_provisioner' => 'test.admin_provisioner',
        'profiles'          => ['fresh_install' => ['steps' => [
            ['type' => 'admin_user'],
            ['type' => 'marker', 'write_done' => true],
        ]]],
    ]];

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/sbb-cg-' . bin2hex(random_bytes(6));
        (new Filesystem())->mkdir($this->dir . '/bin');
        file_put_contents($this->dir . '/.env.local', "APP_SECRET=x\n");
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    public function testAnonymousGetNeitherStartsSetupNorRedirectsTheSite(): void
    {
        $kernel = $this->kernel();
        $cookie = null;

        self::assertSame('HOST HOME PAGE', $this->send($kernel, Request::create('/'), $cookie)->getContent());

        // A page view must never start a run (no setup.required, no site-wide redirect).
        $setup = $this->send($kernel, Request::create('/_setup'), $cookie);
        self::assertSame(302, $setup->getStatusCode());
        self::assertSame('/', $setup->headers->get('Location'));
        self::assertFileDoesNotExist($this->marker('setup.required'));
        self::assertFileDoesNotExist($this->marker('setup-progress.json'));

        $other = null;
        $home  = $this->send($kernel, Request::create('/'), $other);
        self::assertSame(200, $home->getStatusCode());
        self::assertSame('HOST HOME PAGE', $home->getContent());

        $post = $this->send($kernel, Request::create('/_setup', 'POST', ['bootstrap_mode' => 'guided']), $cookie);
        self::assertSame(302, $post->getStatusCode());
        self::assertSame(403, $this->send($kernel, Request::create('/_setup/api/progress'), $cookie)->getStatusCode());
        self::assertSame(404, $this->send($kernel, $this->advance(['profile' => 'fresh_install']), $cookie)->getStatusCode());
        self::assertFileDoesNotExist($this->marker('setup.required'));
        self::assertFileDoesNotExist($this->dir . '/admins.json');
        self::assertFileDoesNotExist($this->dir . '/console.log');
    }

    public function testAnonymousCannotCreateAnAdminWhileSetupIsRequired(): void
    {
        // Setup genuinely required (operator / restore marked it) on a host without setup_token.
        (new Filesystem())->dumpFile($this->marker('setup.required'), "fresh_install\n");
        $kernel = $this->kernel(self::PROFILE);
        $cookie = null;

        $gate = $this->send($kernel, Request::create('/_setup'), $cookie);
        self::assertSame(403, $gate->getStatusCode());
        self::assertStringContainsString('data-testid="nowo-sbb-token-gate"', (string) $gate->getContent());
        // Mid-setup host: the gate says how to get a token.
        self::assertStringContainsString('php bin/console nowo:site-backup:setup-token', (string) $gate->getContent());

        self::assertSame(403, $this->send($kernel, Request::create('/_setup?token=guess'), $cookie)->getStatusCode());

        $admin = $this->send($kernel, Request::create('/_setup', 'POST', [
            '_csrf_token' => $this->csrf((string) $gate->getContent()),
            'email'       => 'attacker@evil.example',
            'password'    => $this->password(),
        ]), $cookie);
        self::assertSame(403, $admin->getStatusCode());

        $api = $this->send($kernel, $this->advance(['email' => 'attacker@evil.example', 'password' => $this->password()]), $cookie);
        self::assertSame(403, $api->getStatusCode());
        self::assertSame(403, $this->send($kernel, Request::create('/_setup/api/progress'), $cookie)->getStatusCode());

        self::assertFileDoesNotExist($this->dir . '/admins.json');
        self::assertFileDoesNotExist($this->marker('setup.done'));
        self::assertFileDoesNotExist($this->marker('setup-progress.json'));
    }

    public function testOneTimeTokenFromTheConsoleRunsTheFirstInstall(): void
    {
        $kernel = $this->kernel(self::PROFILE);
        $cookie = null;

        self::assertSame(302, $this->send($kernel, Request::create('/_setup'), $cookie)->getStatusCode());

        $tester = new CommandTester((new Application($kernel))->find('nowo:site-backup:setup-token'));
        $tester->execute(['--open' => null]);
        self::assertSame(1, preg_match('/Token: ([0-9a-f]{48})/', $tester->getDisplay(), $m));
        $token = $m[1];
        self::assertFileExists($this->marker('setup.token'));
        self::assertFileExists($this->marker('setup.required'));

        // Without the token the wizard stays closed to anonymous visitors.
        self::assertSame(403, $this->send($kernel, Request::create('/_setup'), $cookie)->getStatusCode());
        self::assertSame(403, $this->send($kernel, Request::create('/_setup?token=' . $token . 'x'), $cookie)->getStatusCode());

        // The GET renders the wizard but does not start a run.
        $start = $this->send($kernel, Request::create('/_setup?token=' . $token), $cookie);
        self::assertSame(200, $start->getStatusCode());
        self::assertStringContainsString('data-phase="idle"', (string) $start->getContent());
        self::assertFileDoesNotExist($this->marker('setup-progress.json'));

        // A POST without a valid CSRF token does not advance either.
        $badCsrf = $this->send($kernel, Request::create('/_setup?token=' . $token, 'POST', ['_csrf_token' => 'forged']), $cookie);
        self::assertSame(200, $badCsrf->getStatusCode());
        self::assertFileDoesNotExist($this->marker('setup-progress.json'));

        $adminForm = $this->send($kernel, Request::create('/_setup?token=' . $token, 'POST', ['_csrf_token' => $this->csrf((string) $start->getContent())]), $cookie);
        self::assertStringContainsString('name="email"', (string) $adminForm->getContent());

        $done = $this->send($kernel, Request::create('/_setup?token=' . $token, 'POST', [
            '_csrf_token' => $this->csrf((string) $adminForm->getContent()),
            'email'       => 'owner@example.com',
            'password'    => $this->password(),
        ]), $cookie);
        self::assertSame(302, $done->getStatusCode());
        self::assertStringEndsWith('/_setup/done', (string) $done->headers->get('Location'));
        self::assertFileExists($this->dir . '/admins.json');
        self::assertFileExists($this->marker('setup.done'));
        // One-time: the token is removed and the wizard is closed for good.
        self::assertFileDoesNotExist($this->marker('setup.token'));
        $closed = $this->send($kernel, Request::create('/_setup?token=' . $token), $cookie);
        self::assertSame(302, $closed->getStatusCode());
        self::assertSame('/', $closed->headers->get('Location'));
        self::assertSame(403, $this->send($kernel, Request::create('/_setup/api/progress?token=' . $token), $cookie)->getStatusCode());
    }

    public function testStaticTokenGatesThePageAndTheApi(): void
    {
        (new Filesystem())->dumpFile($this->marker('setup.required'), "fresh_install\n");
        $kernel = $this->kernel(['setup' => ['setup_token' => self::STATIC_TOKEN]] + self::PROFILE);
        $cookie = null;

        $gate = $this->send($kernel, Request::create('/_setup'), $cookie);
        self::assertSame(403, $gate->getStatusCode());
        self::assertStringNotContainsString('nowo:site-backup:setup-token', (string) $gate->getContent());
        self::assertSame(403, $this->send($kernel, $this->advance([]), $cookie)->getStatusCode());
        self::assertSame(200, $this->send($kernel, Request::create('/_setup?token=' . self::STATIC_TOKEN), $cookie)->getStatusCode());

        $api = $this->advance([]);
        $api->headers->set('X-Setup-Token', self::STATIC_TOKEN);
        $progress = $this->send($kernel, $api, $cookie);
        self::assertSame(200, $progress->getStatusCode());
        self::assertStringContainsString('"phase":"waiting_input"', (string) $progress->getContent());
    }

    public function testDoneMarkerClosesTheWizardWhateverTheProgressFileSays(): void
    {
        // CG I1: setup.done written (CLI / earlier run) while the progress file is lost (idle) or stale (running).
        foreach ([null, ['phase' => 'running', 'profile' => 'fresh_install']] as $i => $progress) {
            $fs = new Filesystem();
            $fs->dumpFile($this->marker('setup.done'), "done\n");
            if ($progress !== null) {
                $fs->dumpFile($this->marker('setup-progress.json'), (string) json_encode($progress));
            }
            $kernel = $this->kernel(['setup' => ['setup_token' => self::STATIC_TOKEN, 'brand_name' => 'case ' . $i]] + self::PROFILE);
            $cookie = null;

            $page = $this->send($kernel, Request::create('/_setup?token=' . self::STATIC_TOKEN), $cookie);
            self::assertSame(302, $page->getStatusCode());
            self::assertSame('/', $page->headers->get('Location'));

            $api = $this->advance(['email' => 'second@example.com', 'password' => $this->password()]);
            $api->headers->set('X-Setup-Token', self::STATIC_TOKEN);
            self::assertSame(404, $this->send($kernel, $api, $cookie)->getStatusCode());
            // The read-only progress API stays token-gated (pollers read the final phase).
            self::assertSame(200, $this->send($kernel, Request::create('/_setup/api/progress?token=' . self::STATIC_TOKEN), $cookie)->getStatusCode());
            self::assertSame(200, $this->send($kernel, Request::create('/'), $cookie)->getStatusCode());
            self::assertFileDoesNotExist($this->dir . '/admins.json');
        }
    }

    public function testAllowWithoutTokenIsDebugOnly(): void
    {
        (new Filesystem())->dumpFile($this->marker('setup.required'), "fresh_install\n");
        $config = ['setup' => ['allow_without_token' => true]] + self::PROFILE;

        $debug  = $this->kernel($config, true);
        $cookie = null;
        $page   = $this->send($debug, Request::create('/_setup'), $cookie);
        self::assertSame(200, $page->getStatusCode());
        self::assertFileDoesNotExist($this->marker('setup-progress.json'));
        // The opt-out never opens the JSON API (it has no form CSRF token).
        self::assertSame(403, $this->send($debug, $this->advance([]), $cookie)->getStatusCode());
        $debug->shutdown();

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('only allowed when kernel.debug is true');
        $this->kernel($config, false);
    }

    public function testAllowWithoutTokenMustBeALiteralBoolean(): void
    {
        // An %env(bool:…)% placeholder is a non-empty string at build time and would read as true (fail open).
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('must be a literal boolean');
        $this->kernel(['setup' => ['allow_without_token' => '%env(bool:SETUP_OPEN)%']], true);
    }

    /** @param array<string, mixed> $config */
    private function kernel(array $config = [], bool $debug = false): SetupSecurityKernel
    {
        $kernel = new SetupSecurityKernel($this->dir, $config, $debug);
        $kernel->boot();

        return $kernel;
    }

    /** @param array<string, mixed> $payload */
    private function advance(array $payload): Request
    {
        return Request::create('/_setup/api/advance', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode($payload));
    }

    /** Generated per run: the suite never carries a literal credential. */
    private function password(): string
    {
        return 'Aa1!' . bin2hex(random_bytes(8));
    }

    private function marker(string $name): string
    {
        return $this->dir . '/var/site-backup/' . $name;
    }

    private function send(SetupSecurityKernel $kernel, Request $request, ?string &$cookie): Response
    {
        if ($cookie !== null) {
            $request->cookies->set('MOCKSESSID', $cookie);
        }
        $response = $kernel->handle($request);
        foreach ($response->headers->getCookies() as $sent) {
            if ($sent->getName() === 'MOCKSESSID') {
                $cookie = $sent->getValue();
            }
        }
        if ($request->hasSession() && $request->getSession()->isStarted()) {
            $cookie ??= $request->getSession()->getId();
            $request->getSession()->save();
        }
        $kernel->terminate($request, $response);

        return $response;
    }

    private function csrf(string $html): string
    {
        return preg_match('/name="_csrf_token"[^>]*value="([^"]+)"/', $html, $m) === 1 || preg_match('/value="([^"]+)"[^>]*name="_csrf_token"/', $html, $m) === 1 ? $m[1] : '';
    }
}
