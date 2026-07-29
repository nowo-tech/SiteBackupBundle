<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Setup\Step;

use Nowo\SiteBackupBundle\Setup\SetupContext;
use Nowo\SiteBackupBundle\Setup\SetupStepInput;
use Nowo\SiteBackupBundle\Setup\SetupStepInterface;
use Nowo\SiteBackupBundle\Setup\SetupStepResult;
use Nowo\SiteBackupBundle\Setup\SetupTabCheckerInterface;

/**
 * Wraps a setup step with optional tab metadata (template, i18n domain) and a YAML-bound checker.
 */
final class TabStep implements SetupStepInterface
{
    public function __construct(
        private readonly SetupStepInterface $inner,
        private readonly ?SetupTabCheckerInterface $checker = null,
        private readonly ?string $template = null,
        private readonly string $labelDomain = 'NowoSiteBackupBundle',
        private readonly ?string $description = null,
    ) {
    }

    public function getId(): string
    {
        return $this->inner->getId();
    }

    public function getLabel(): string
    {
        return $this->inner->getLabel();
    }

    public function getUiKind(): string
    {
        return $this->inner->getUiKind();
    }

    public function getTemplate(): ?string
    {
        return $this->template;
    }

    public function getLabelDomain(): string
    {
        return $this->labelDomain;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getInner(): SetupStepInterface
    {
        return $this->inner;
    }

    public function isEnabled(SetupContext $ctx): bool
    {
        return $this->inner->isEnabled($ctx);
    }

    public function isComplete(SetupContext $ctx): bool
    {
        if ($this->inner->isComplete($ctx)) {
            return true;
        }

        return $this->checker instanceof SetupTabCheckerInterface && $this->checker->check($ctx)->isOk()

        ;
    }

    public function run(SetupContext $ctx, SetupStepInput $input): SetupStepResult
    {
        if ($this->checker instanceof SetupTabCheckerInterface) {
            $check = $this->checker->check($ctx);
            if ($check->isOk()) {
                return SetupStepResult::ok($check->getMessage() !== '' ? $check->getMessage() : 'setup.check.ok');
            }
            if ($check->isBlocked()) {
                return SetupStepResult::fail($check->getMessage() !== '' ? $check->getMessage() : 'setup.check.blocked');
            }
            if ($check->needsInput()) {
                $force = $input->getString('action') === 'continue'
                    || $input->getBool('continue')
                    || $input->getString('bootstrap_mode') !== ''
                    || $input->getString('email') !== ''
                    || $input->getString('password') !== '';
                if (!$force) {
                    return SetupStepResult::waitingForInput(
                        $check->getMessage() !== '' ? $check->getMessage() : 'setup.check.needs_input',
                    );
                }
            }
        }

        return $this->inner->run($ctx, $input);
    }
}
