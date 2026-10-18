<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Support;

use DateTimeImmutable;
use Nowo\SiteBackupBundle\Form\Panel\CreateBackupType;
use Nowo\SiteBackupBundle\Form\Panel\PanelActionType;
use Nowo\SiteBackupBundle\Form\Panel\PanelLoginType;
use Nowo\SiteBackupBundle\Form\Setup\AdminUserType;
use Nowo\SiteBackupBundle\Form\Setup\BootstrapModeType;
use Nowo\SiteBackupBundle\Form\Setup\ContinueType;
use Nowo\SiteBackupBundle\Form\Setup\DatabaseUrlType;
use Nowo\SiteBackupBundle\Form\Setup\ResetType;
use Nowo\SiteBackupBundle\Form\Setup\SampleDataType;
use Nowo\SiteBackupBundle\Form\Setup\SetupTokenType;
use Nowo\SiteBackupBundle\Model\BackupArtifact;
use Nowo\SiteBackupBundle\Model\BackupHistoryEntry;
use Nowo\SiteBackupBundle\Model\RestoreProgress;
use Nowo\SiteBackupBundle\Model\SetupProgress;
use Nowo\SiteBackupBundle\Setup\NullAdminUserProvisioner;
use Nowo\SiteBackupBundle\Setup\SetupStepInterface;
use Nowo\SiteBackupBundle\Setup\Step\AdminUserStep;
use Nowo\SiteBackupBundle\Setup\Step\BootstrapModeStep;
use Nowo\SiteBackupBundle\Setup\Step\DatabaseUrlStep;
use Nowo\SiteBackupBundle\Setup\Step\RequirementsStep;
use Nowo\SiteBackupBundle\View\PanelPages;
use Nowo\SiteBackupBundle\View\SetupPages;
use Nowo\SiteBackupBundle\View\SiteBackupView;
use Nowo\UiKitBundle\Test\StubHost;
use Nowo\UiKitBundle\View\Action;
use Symfony\Component\Form\Extension\Csrf\CsrfExtension;
use Symfony\Component\Form\Extension\HttpFoundation\HttpFoundationExtension;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\Form\FormView;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * Realistic page contexts of the panel and setup pages, built with the same view-model factories as the
 * controllers ({@see PanelPages}, {@see SetupPages}) — used by the contract test and the render tests.
 */
final class PageFixtures
{
    public const PREFIX = '/_site_backup';

    public const SETUP_PREFIX = '/_setup';

    public static function view(?string $rowActionsDisplay = null): SiteBackupView
    {
        $twig = new Environment(new ArrayLoader());
        $twig->addGlobal('nowo_ui_layout', ['admin' => StubHost::LAYOUT_ADMIN, 'guest' => StubHost::LAYOUT_GUEST]);

        return new SiteBackupView($twig, self::PREFIX, rowActionsDisplay: $rowActionsDisplay);
    }

    public static function formFactoryWith(CsrfTokenManagerInterface $csrf): FormFactoryInterface
    {
        return Forms::createFormFactoryBuilder()
            ->addExtension(new HttpFoundationExtension())
            ->addExtension(new CsrfExtension($csrf))
            ->getFormFactory();
    }

    public static function formFactory(): FormFactoryInterface
    {
        $csrf = new class implements CsrfTokenManagerInterface {
            public function getToken(string $tokenId): CsrfToken
            {
                return new CsrfToken($tokenId, 'token-' . $tokenId);
            }

            public function refreshToken(string $tokenId): CsrfToken
            {
                return $this->getToken($tokenId);
            }

            public function removeToken(string $tokenId): ?string
            {
                return null;
            }

            public function isTokenValid(CsrfToken $token): bool
            {
                return true;
            }
        };

        return Forms::createFormFactoryBuilder()->addExtension(new CsrfExtension($csrf))->getFormFactory();
    }

    /**
     * @return list<BackupArtifact>
     */
    public static function backups(): array
    {
        return [
            new BackupArtifact('20261018-101500-a1b2c3', '20261018-101500-a1b2c3.tar.gz', '/var/site-backup/archives/20261018-101500-a1b2c3.tar.gz', new DateTimeImmutable('2026-10-18 10:15:00'), 48_213_504, str_repeat('a', 64), label: 'pre-deploy', createdBy: 'panel'),
            new BackupArtifact('20261017-231000-d4e5f6', '20261017-231000-d4e5f6.tar.gz', '/var/site-backup/archives/20261017-231000-d4e5f6.tar.gz', new DateTimeImmutable('2026-10-17 23:10:00'), 47_901_184, str_repeat('b', 64), createdBy: 'cli'),
        ];
    }

    /**
     * @return list<BackupHistoryEntry>
     */
    public static function history(): array
    {
        return [
            new BackupHistoryEntry('restore_complete', new DateTimeImmutable('2026-10-18 10:20:31'), 'panel', '20261018-101500-a1b2c3', 'Restore completed successfully.'),
            new BackupHistoryEntry('restore_start', new DateTimeImmutable('2026-10-18 10:19:02'), 'panel', '20261018-101500-a1b2c3'),
            new BackupHistoryEntry('create', new DateTimeImmutable('2026-10-18 10:15:00'), 'panel', '20261018-101500-a1b2c3', 'pre-deploy'),
            new BackupHistoryEntry('delete', new DateTimeImmutable('2026-10-16 08:00:00'), 'cli', '20261001-080000-ffffff'),
        ];
    }

    public static function actionForm(string $action, ?string $backupId = null): FormView
    {
        return self::formFactory()->createNamed('', PanelActionType::class, null, ['panel_action' => $action, 'backup_id' => $backupId])->createView();
    }

    /**
     * @param list<BackupArtifact> $backups
     * @param array<string, list<string>> $messages
     *
     * @return array<string, mixed>
     */
    public static function index(array $backups, ?RestoreProgress $progress = null, array $messages = [], ?string $rowActionsDisplay = null): array
    {
        $forms = [];
        foreach ($backups as $backup) {
            $forms[$backup->getId()] = [
                'verify'  => self::actionForm('verify', $backup->getId()),
                'restore' => self::actionForm('restore', $backup->getId()),
                'delete'  => self::actionForm('delete', $backup->getId()),
            ];
        }

        return [
            'page'          => (new PanelPages(self::view($rowActionsDisplay)))->index($backups, $forms, self::actionForm('logout')),
            'messages'      => $messages,
            'progress'      => $progress ?? new RestoreProgress(),
            'clear_restore' => new Action('custom', SiteBackupView::label('panel.index.clear_status'), self::PREFIX . '/', method: 'POST', form: self::actionForm('clear_restore')),
            'path_prefix'   => self::PREFIX,
        ];
    }

    /**
     * @param list<BackupHistoryEntry> $entries
     *
     * @return array<string, mixed>
     */
    public static function historyPage(array $entries): array
    {
        return ['page' => (new PanelPages(self::view()))->history($entries), 'messages' => []];
    }

    /**
     * @param array<string, list<string>> $messages
     *
     * @return array<string, mixed>
     */
    public static function create(array $messages = []): array
    {
        $form = self::formFactory()->createNamed('', CreateBackupType::class, null, ['action' => self::PREFIX])->createView();

        return ['page' => (new PanelPages(self::view()))->create($form), 'messages' => $messages];
    }

    /**
     * @param 'delete'|'restore' $kind
     *
     * @return array<string, mixed>
     */
    public static function confirm(string $kind): array
    {
        $id = self::backups()[0]->getId();

        return ['page' => (new PanelPages(self::view()))->confirm($kind, $id, self::actionForm($kind, $id)), 'messages' => []];
    }

    /**
     * @return array<string, mixed>
     */
    public static function login(?string $error = null, bool $misconfigured = false, bool $protected = true): array
    {
        $form = self::formFactory()->createNamed('', PanelLoginType::class)->createView();
        $err  = $misconfigured ? SiteBackupView::label('panel.login.misconfigured', ['%option%' => 'security.password_hash', '%command%' => 'nowo:site-backup:hash-password']) : $error;

        return ['page' => (new PanelPages(self::view()))->login($misconfigured || !$protected ? null : $form, $err, $protected), 'messages' => []];
    }

    /**
     * @return list<SetupStepInterface>
     */
    public static function steps(): array
    {
        return [
            new RequirementsStep('requirements', 'setup.tab.requirements'),
            new BootstrapModeStep('bootstrap_mode', 'setup.tab.bootstrap_mode'),
            new DatabaseUrlStep('database_url', 'setup.tab.database_url'),
            new AdminUserStep('admin_user', 'setup.tab.admin_user', new NullAdminUserProvisioner()),
        ];
    }

    /**
     * Wizard page in a given state: `admin_user` (waiting for the admin form), `database_url` (DB unreachable),
     * `bootstrap_mode`, `sample`, `running` (automatic advance), `failed`, `idle`.
     *
     * @return array<string, mixed>
     */
    public static function wizard(string $state): array
    {
        $factory   = self::formFactory();
        $steps     = self::steps();
        $reasons   = [];
        $error     = null;
        $completed = ['requirements', 'bootstrap_mode', 'database_url'];
        $log       = ['[requirements] PHP 8.4, ext-json, ext-pdo OK', '[database_url] kept DATABASE_URL from .env', '[database_create] database created'];

        [$phase, $currentId, $form] = match ($state) {
            'database_url'   => [SetupProgress::PHASE_WAITING, 'database_url', $factory->createNamed('', DatabaseUrlType::class, ['database_url' => ''], ['db_connection_failed' => true, 'optional' => true])],
            'bootstrap_mode' => [SetupProgress::PHASE_WAITING, 'bootstrap_mode', $factory->createNamed('', BootstrapModeType::class, ['sql_import_path' => ''])],
            'sample'         => [SetupProgress::PHASE_WAITING, 'sample_data', $factory->createNamed('', SampleDataType::class)],
            'running'        => [SetupProgress::PHASE_RUNNING, 'database_url', $factory->createNamed('', ContinueType::class)],
            'failed'         => [SetupProgress::PHASE_FAILED, 'admin_user', $factory->createNamed('', ResetType::class)],
            'idle'           => [SetupProgress::PHASE_IDLE, null, $factory->createNamed('', ContinueType::class)],
            default          => [SetupProgress::PHASE_WAITING, 'admin_user', $factory->createNamed('', AdminUserType::class, ['email' => '', 'password' => ''])],
        };
        if ($state === 'database_url') {
            $reasons   = ['database connection failed', 'setup.required marker present'];
            $completed = ['requirements', 'bootstrap_mode'];
        }
        if ($state === 'sample') {
            $steps[] = new RequirementsStep('sample_data', 'setup.tab.sample_data');
        }
        if ($state === 'failed') {
            $error = 'Doctrine migrations failed: SQLSTATE[HY000] [2002] Connection refused';
        }
        if ($state === 'idle') {
            $completed = [];
            $log       = [];
            $reasons   = ['setup.required marker present', 'incomplete setup progress (fresh_install)'];
        }

        $progress    = new SetupProgress($phase, 'fresh_install', $currentId, $state === 'idle' ? 0.0 : 62.5, $state === 'idle' ? null : 'Waiting for input', $error, $completed, $log);
        $currentStep = null;
        foreach ($steps as $step) {
            if ($step->getId() === $currentId) {
                $currentStep = $step;
            }
        }
        $view = $form->createView();

        return [
            'page'        => (new SetupPages(self::view(), 'Acme Shop'))->wizard($steps, $progress, $currentStep, $view),
            'progress'    => $progress,
            'steps'       => $steps,
            'currentStep' => $currentStep,
            'reasons'     => $reasons,
            'error'       => null,
            'progressUrl' => self::SETUP_PREFIX . '/api/progress',
            'advanceMode' => 'automatic',
            'wizardForm'  => $view,
            'pathPrefix'  => self::SETUP_PREFIX,
            'brandName'   => 'Acme Shop',
            'messages'    => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function done(): array
    {
        return ['page' => (new SetupPages(self::view(), 'Acme Shop'))->done(self::steps()), 'progress' => new SetupProgress(SetupProgress::PHASE_COMPLETED, percent: 100.0), 'pathPrefix' => self::SETUP_PREFIX, 'brandName' => 'Acme Shop', 'messages' => []];
    }

    /**
     * @return array<string, mixed>
     */
    public static function token(bool $withForm = true): array
    {
        $form = $withForm ? self::formFactory()->createNamed('', SetupTokenType::class, null, ['action' => self::SETUP_PREFIX])->createView() : null;

        return ['page' => (new SetupPages(self::view(), 'Acme Shop'))->token($form, self::SETUP_PREFIX), 'pathPrefix' => self::SETUP_PREFIX, 'brandName' => 'Acme Shop', 'messages' => []];
    }
}
