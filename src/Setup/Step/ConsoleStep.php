<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Setup\Step;

use Nowo\SiteBackupBundle\Setup\ConsoleProcessRunner;
use Nowo\SiteBackupBundle\Setup\SetupContext;
use Nowo\SiteBackupBundle\Setup\SetupStepInput;
use Nowo\SiteBackupBundle\Setup\SetupStepResult;
use Throwable;

use function implode;
use function trim;

final class ConsoleStep extends AbstractSetupStep
{
    /**
     * @param list<string> $commandArgs e.g. ['cache:clear'] or ['app:seed-roles']
     */
    public function __construct(
        string $id,
        string $label,
        private readonly ConsoleProcessRunner $runner,
        private readonly array $commandArgs,
    ) {
        parent::__construct($id, $label);
    }

    public function run(SetupContext $ctx, SetupStepInput $input): SetupStepResult
    {
        try {
            $output = $this->runner->runOrFail($this->commandArgs);

            return SetupStepResult::ok('Command OK: ' . implode(' ', $this->commandArgs), [trim($output)]);
        } catch (Throwable $e) {
            return SetupStepResult::fail($e->getMessage());
        }
    }
}
