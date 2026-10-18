<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\View;

use Nowo\SiteBackupBundle\Model\BackupArtifact;
use Nowo\UiKitBundle\View\Action;
use Nowo\UiKitBundle\View\AuthPage;
use Nowo\UiKitBundle\View\Column;
use Nowo\UiKitBundle\View\Confirm;
use Nowo\UiKitBundle\View\ConfirmPage;
use Nowo\UiKitBundle\View\EmptyState;
use Nowo\UiKitBundle\View\FormPage;
use Nowo\UiKitBundle\View\Label;
use Nowo\UiKitBundle\View\ListPage;
use Nowo\UiKitBundle\View\PageHeader;
use Symfony\Component\Form\FormView;

use function rawurlencode;
use function rtrim;

/**
 * Builds the UiKit view models of the backup panel (REQ-UI-003): archives list, history, create form, the no-JS
 * restore / delete confirmations and the guest login. Plain data in, plain data out (no services, no request), so
 * the controller and the contract test fixtures build exactly the same pages.
 */
final readonly class PanelPages
{
    public function __construct(
        private SiteBackupView $view,
    ) {
    }

    public static function restoreConfirm(string $backupId): Confirm
    {
        return new Confirm(
            SiteBackupView::label('panel.confirm.restore_title', ['%id%' => $backupId]),
            SiteBackupView::label('panel.index.restore_confirm'),
            SiteBackupView::label('panel.index.restore'),
            'danger',
            expectedValue: $backupId,
            expectedLabel: SiteBackupView::label('panel.confirm.restore_expected'),
        );
    }

    public static function deleteConfirm(string $backupId): Confirm
    {
        return new Confirm(
            SiteBackupView::label('panel.index.delete_confirm'),
            SiteBackupView::label('panel.confirm.delete_message', ['%id%' => $backupId]),
            SiteBackupView::label('panel.index.delete'),
        );
    }

    /**
     * Archives list: header actions Create backup (link to the create form) and Logout (POST + CSRF), the area nav,
     * one row per archive with Verify / Restore (type-to-confirm) / Delete (confirm) POST actions.
     *
     * @param list<BackupArtifact> $backups
     * @param array<string, array{verify: FormView, restore: FormView, delete: FormView}> $rowForms backup id => CSRF action forms
     */
    public function index(array $backups, array $rowForms, ?FormView $logoutForm): ListPage
    {
        $actions = [new Action('create', SiteBackupView::label('panel.index.create_title'), $this->url('/create'), variant: 'primary', attributes: ['data-testid' => 'nowo-sbb-create'])];
        if ($logoutForm instanceof FormView) {
            $actions[] = new Action('custom', SiteBackupView::label('panel.index.logout'), $this->view->pathPrefix, method: 'POST', icon: 'box-arrow-right', attributes: ['data-testid' => 'nowo-sbb-logout'], form: $logoutForm);
        }

        $rowActions = [];
        foreach ($backups as $backup) {
            $id = $backup->getId();
            if (!isset($rowForms[$id])) {
                continue;
            }

            $rowActions[$id] = $this->rowActions($id, $rowForms[$id]);
        }

        return new ListPage(
            new PageHeader(SiteBackupView::label('panel.index.page_title'), null, $actions, tabs: $this->view->nav('panel')),
            [
                new Column('id', SiteBackupView::label('panel.index.col_id'), required: true),
                new Column('createdAt', SiteBackupView::label('panel.index.col_created')),
                new Column('sizeBytes', SiteBackupView::label('panel.index.col_size'), align: 'end', priority: 2),
                new Column('label', SiteBackupView::label('panel.index.col_label'), priority: 3),
            ],
            $backups,
            rowActions: static fn (BackupArtifact $backup): array => $rowActions[$backup->getId()] ?? [],
            empty: new EmptyState(SiteBackupView::label('panel.index.empty'), SiteBackupView::label('panel.index.empty_hint'), Action::create($this->url('/create'), SiteBackupView::label('panel.index.create_title'))),
            caption: SiteBackupView::label('panel.index.archives'),
            bundle: SiteBackupView::BUNDLE,
            layout: $this->view->layout(),
            attributes: ['data-testid' => 'nowo-sbb-archives'],
        );
    }

    /**
     * @param list<object> $entries BackupHistoryEntry rows (newest first)
     */
    public function history(array $entries): ListPage
    {
        return new ListPage(
            new PageHeader(SiteBackupView::label('panel.history.title'), SiteBackupView::label('panel.history.intro'), tabs: $this->view->nav('history')),
            [
                new Column('occurredAt', SiteBackupView::label('panel.history.col_when'), required: true),
                new Column('action', SiteBackupView::label('panel.history.col_action')),
                new Column('backupId', SiteBackupView::label('panel.history.col_backup'), priority: 2),
                new Column('actor', SiteBackupView::label('panel.history.col_actor'), priority: 3),
                new Column('message', SiteBackupView::label('panel.history.col_message'), priority: 3),
            ],
            $entries,
            empty: new EmptyState(SiteBackupView::label('panel.history.empty')),
            bundle: SiteBackupView::BUNDLE,
            layout: $this->view->layout(),
            attributes: ['data-testid' => 'nowo-sbb-history'],
        );
    }

    public function create(FormView $form): FormPage
    {
        return new FormPage(
            new PageHeader(SiteBackupView::label('panel.index.create_title'), SiteBackupView::label('panel.create.intro'), tabs: $this->view->nav('panel')),
            $form,
            SiteBackupView::label('panel.index.create_submit'),
            $this->view->pathPrefix,
            bundle: SiteBackupView::BUNDLE,
            layout: $this->view->layout(),
            attributes: ['data-testid' => 'nowo-sbb-create-form'],
        );
    }

    /**
     * No-JS fallback of the restore / delete dialogs ({@code Action::fallbackHref}): same Confirm, same CSRF form.
     *
     * @param 'delete'|'restore' $kind
     */
    public function confirm(string $kind, string $backupId, FormView $form): ConfirmPage
    {
        $restore = $kind === 'restore';

        return new ConfirmPage(
            new PageHeader(SiteBackupView::label($restore ? 'panel.confirm.restore_page_title' : 'panel.confirm.delete_page_title'), tabs: $this->view->nav('panel')),
            $restore ? self::restoreConfirm($backupId) : self::deleteConfirm($backupId),
            new Action($restore ? 'restore' : 'delete', SiteBackupView::label($restore ? 'panel.index.restore' : 'panel.index.delete'), $this->view->pathPrefix, method: 'POST', form: $form),
            $this->view->pathPrefix,
            SiteBackupView::BUNDLE,
            $this->view->layout(),
            ['data-testid' => 'nowo-sbb-' . $kind . '-confirm'],
        );
    }

    /**
     * Panel sign-in on the guest layout. Without a form (misconfigured gate) the page shows the error only.
     */
    public function login(?FormView $form, Label|string|null $error, bool $protectionEnabled): AuthPage
    {
        return new AuthPage(
            SiteBackupView::label('panel.login.heading'),
            $form,
            $protectionEnabled ? SiteBackupView::label('panel.login.intro') : SiteBackupView::label('panel.login.unprotected'),
            SiteBackupView::label('panel.title'),
            $error,
            SiteBackupView::label('panel.login.submit'),
            bundle: SiteBackupView::BUNDLE,
            layout: $this->view->guestLayoutOverride(),
            attributes: ['data-testid' => 'nowo-sbb-login'],
        );
    }

    /**
     * @param array{verify: FormView, restore: FormView, delete: FormView} $forms
     *
     * @return list<Action>
     */
    private function rowActions(string $id, array $forms): array
    {
        $display = $this->view->rowActionsDisplay;

        return [
            new Action('custom', SiteBackupView::label('panel.index.verify'), $this->view->pathPrefix, method: 'POST', icon: 'shield-check', attributes: ['data-testid' => 'nowo-sbb-verify'], form: $forms['verify'], display: $display),
            new Action('restore', SiteBackupView::label('panel.index.restore'), $this->view->pathPrefix, method: 'POST', confirm: self::restoreConfirm($id), icon: 'arrow-counterclockwise', variant: 'secondary', attributes: ['data-testid' => 'nowo-sbb-restore'], form: $forms['restore'], display: $display, fallbackHref: $this->url('/restore/' . rawurlencode($id))),
            new Action('delete', SiteBackupView::label('panel.index.delete'), $this->view->pathPrefix, method: 'POST', confirm: self::deleteConfirm($id), attributes: ['data-testid' => 'nowo-sbb-delete'], form: $forms['delete'], display: $display, fallbackHref: $this->url('/delete/' . rawurlencode($id))),
        ];
    }

    private function url(string $path): string
    {
        return rtrim($this->view->pathPrefix, '/') . $path;
    }
}
