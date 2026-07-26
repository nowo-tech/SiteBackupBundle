<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Setup\Step;

use Nowo\SiteBackupBundle\Setup\SetupContext;
use Nowo\SiteBackupBundle\Setup\SetupStepInput;
use Nowo\SiteBackupBundle\Setup\SetupStepInterface;
use Nowo\SiteBackupBundle\Setup\SetupStepResult;

abstract class AbstractSetupStep implements SetupStepInterface
{
    public function __construct(
        protected readonly string $id,
        protected readonly string $label,
        /** @var 'auto'|'confirm'|'form' */
        protected readonly string $uiKind = 'auto',
    ) {
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getUiKind(): string
    {
        /* @var 'auto'|'form'|'confirm' */
        return $this->uiKind;
    }

    public function isEnabled(SetupContext $ctx): bool
    {
        return true;
    }

    public function isComplete(SetupContext $ctx): bool
    {
        return $ctx->isCompleted($this->id);
    }

    abstract public function run(SetupContext $ctx, SetupStepInput $input): SetupStepResult;
}
