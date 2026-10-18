<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\View;

use Nowo\SiteBackupBundle\Model\SetupProgress;
use Nowo\UiKitBundle\View\AuthPage;
use Nowo\UiKitBundle\View\Label;
use Nowo\UiKitBundle\View\PageHeader;
use Nowo\UiKitBundle\View\WizardPage;
use Nowo\UiKitBundle\View\WizardStep;
use Symfony\Component\Form\FormView;

use function in_array;
use function is_string;
use function method_exists;

/**
 * Builds the UiKit view models of the setup wizard (REQ-UI-003): the wizard steps (page/wizard), the final "done"
 * step and the setup-token gate (page/auth). All on the guest layout and free of database services: the wizard
 * renders before the application has a schema, users or a working DATABASE_URL.
 */
final readonly class SetupPages
{
    /** Id of the last (virtual) step shown after the configured steps. */
    public const DONE_STEP = 'nowo_site_backup_done';

    public function __construct(
        private SiteBackupView $view,
        private string $brandName = 'Site Setup',
    ) {
    }

    /**
     * @param list<object> $steps configured steps of the profile (getId() / getLabel() / optional getLabelDomain())
     */
    public function wizard(array $steps, SetupProgress $progress, ?object $currentStep, ?FormView $form): WizardPage
    {
        $wizardSteps = $this->steps($steps);
        $ids         = [];
        foreach ($wizardSteps as $step) {
            $ids[] = $step->id;
        }

        $current = is_string($progress->getCurrentStepId()) && in_array($progress->getCurrentStepId(), $ids, true)
            ? $progress->getCurrentStepId()
            : ($currentStep !== null && method_exists($currentStep, 'getId') && in_array($currentStep->getId(), $ids, true) ? $currentStep->getId() : $this->firstOpen($wizardSteps, $progress));

        return new WizardPage(
            new PageHeader($this->title('setup.page_title.wizard'), SiteBackupView::label('setup.wizard.subtitle')),
            $wizardSteps,
            $current,
            $form,
            bundle: SiteBackupView::BUNDLE,
            layout: $this->view->guestLayout(),
            attributes: ['data-testid' => 'nowo-sbb-setup-wizard', 'data-phase' => $progress->getPhase()],
        );
    }

    /**
     * @param list<object> $steps
     */
    public function done(array $steps): WizardPage
    {
        return new WizardPage(
            new PageHeader($this->title('setup.page_title.done'), SiteBackupView::label('setup.done.body')),
            $this->steps($steps),
            self::DONE_STEP,
            bundle: SiteBackupView::BUNDLE,
            layout: $this->view->guestLayout(),
            attributes: ['data-testid' => 'nowo-sbb-setup-done'],
        );
    }

    /**
     * Setup-token gate (HTTP 403): GET form `token`, on the guest layout.
     */
    public function token(?FormView $form, string $pathPrefix): AuthPage
    {
        return new AuthPage(
            SiteBackupView::label('setup.token.title'),
            $form,
            SiteBackupView::label($form instanceof FormView ? 'setup.token.lead' : 'setup.token.body', ['%path%' => $pathPrefix . '?token=…']),
            $this->brandName,
            submitLabel: SiteBackupView::label('setup.token.submit'),
            bundle: SiteBackupView::BUNDLE,
            layout: $this->view->guestLayoutOverride(),
            attributes: ['data-testid' => 'nowo-sbb-token-gate'],
        );
    }

    /**
     * @param list<object> $steps
     *
     * @return list<WizardStep>
     */
    private function steps(array $steps): array
    {
        $wizardSteps = [];
        foreach ($steps as $step) {
            if (!method_exists($step, 'getId') || !method_exists($step, 'getLabel')) {
                continue;
            }

            $domain        = method_exists($step, 'getLabelDomain') ? (string) $step->getLabelDomain() : SiteBackupView::BUNDLE;
            $wizardSteps[] = new WizardStep((string) $step->getId(), new Label((string) $step->getLabel(), $domain !== '' ? $domain : SiteBackupView::BUNDLE));
        }

        $wizardSteps[] = new WizardStep(self::DONE_STEP, SiteBackupView::label('setup.done.step'));

        return $wizardSteps;
    }

    /**
     * Current step when the progress names none (idle, running between steps): the first step not completed yet.
     *
     * @param list<WizardStep> $steps
     */
    private function firstOpen(array $steps, SetupProgress $progress): string
    {
        foreach ($steps as $step) {
            if (!in_array($step->id, $progress->getCompletedStepIds(), true)) {
                return $step->id;
            }
        }

        return self::DONE_STEP;
    }

    private function title(string $key): Label
    {
        return SiteBackupView::label($key, ['%brand%' => $this->brandName]);
    }
}
