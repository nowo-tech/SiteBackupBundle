<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Setup\Step;

use Nowo\SiteBackupBundle\Setup\SetupContext;
use Nowo\SiteBackupBundle\Setup\SetupStepInput;
use Nowo\SiteBackupBundle\Setup\SetupStepResult;

/**
 * App-owned tab body: waits for Continuar unless a checker/runner handles the work.
 */
final class CustomSetupStep extends AbstractSetupStep
{
    public function __construct(string $id, string $label)
    {
        parent::__construct($id, $label, 'form');
    }

    public function run(SetupContext $ctx, SetupStepInput $input): SetupStepResult
    {
        if ($input->getString('action') === 'continue' || $input->getBool('continue')) {
            return SetupStepResult::ok('setup.check.ok');
        }

        return SetupStepResult::waitingForInput('setup.check.needs_input');
    }
}
