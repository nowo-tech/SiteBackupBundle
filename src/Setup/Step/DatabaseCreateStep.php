<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Setup\Step;

use Nowo\SiteBackupBundle\Setup\ConsoleProcessRunner;
use Nowo\SiteBackupBundle\Setup\SetupContext;
use Nowo\SiteBackupBundle\Setup\SetupStepInput;
use Nowo\SiteBackupBundle\Setup\SetupStepResult;
use Throwable;

final class DatabaseCreateStep extends AbstractSetupStep
{
    public function __construct(
        string $id,
        string $label,
        private readonly ConsoleProcessRunner $runner,
    ) {
        parent::__construct($id, $label, 'auto');
    }

    public function run(SetupContext $ctx, SetupStepInput $input): SetupStepResult
    {
        try {
            $output = $this->runner->runOrFail(['doctrine:database:create', '--if-not-exists']);

            return SetupStepResult::ok('Database created (or already exists).', [trim($output)]);
        } catch (Throwable $e) {
            return SetupStepResult::fail($e->getMessage());
        }
    }
}
