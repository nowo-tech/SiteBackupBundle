<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Setup\Step;

use Nowo\SiteBackupBundle\Setup\ConsoleProcessRunner;
use Nowo\SiteBackupBundle\Setup\SetupContext;
use Nowo\SiteBackupBundle\Setup\SetupStepInput;
use Nowo\SiteBackupBundle\Setup\SetupStepResult;
use Throwable;

final class CacheClearStep extends AbstractSetupStep
{
    public function __construct(
        string $id,
        string $label,
        private readonly ConsoleProcessRunner $runner,
    ) {
        parent::__construct($id, $label);
    }

    public function run(SetupContext $ctx, SetupStepInput $input): SetupStepResult
    {
        try {
            $output = $this->runner->runOrFail(['cache:clear']);

            return SetupStepResult::ok('Cache cleared.', [trim($output)]);
        } catch (Throwable $e) {
            return SetupStepResult::fail($e->getMessage());
        }
    }
}
