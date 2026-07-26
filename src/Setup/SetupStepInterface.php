<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Setup;

interface SetupStepInterface
{
    public function getId(): string;

    public function getLabel(): string;

    /**
     * @return 'auto'|'confirm'|'form'
     */
    public function getUiKind(): string;

    public function isEnabled(SetupContext $ctx): bool;

    public function isComplete(SetupContext $ctx): bool;

    public function run(SetupContext $ctx, SetupStepInput $input): SetupStepResult;
}
