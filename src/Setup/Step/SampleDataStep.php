<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Setup\Step;

use Nowo\SiteBackupBundle\Setup\ConsoleProcessRunner;
use Nowo\SiteBackupBundle\Setup\SetupContext;
use Nowo\SiteBackupBundle\Setup\SetupStepInput;
use Nowo\SiteBackupBundle\Setup\SetupStepResult;
use Throwable;

/**
 * Opt-in sample data: form confirm, then runs configured console commands.
 */
final class SampleDataStep extends AbstractSetupStep
{
    /**
     * @param list<list<string>> $commands
     */
    public function __construct(
        string $id,
        string $label,
        private readonly ConsoleProcessRunner $runner,
        private readonly array $commands = [],
        private readonly string $when = 'opt_in',
    ) {
        parent::__construct($id, $label, 'form');
    }

    public function isEnabled(SetupContext $ctx): bool
    {
        return $this->commands !== [];
    }

    public function run(SetupContext $ctx, SetupStepInput $input): SetupStepResult
    {
        $action = $input->getString('action');
        if ($this->when === 'opt_in') {
            if ($action === 'skip' || $input->getBool('skip')) {
                $ctx->setAnswer('sample_data', false);

                return SetupStepResult::ok('Sample data skipped.');
            }
            if ($action !== 'load' && !$input->getBool('load') && !$ctx->wantsSampleData()) {
                return SetupStepResult::waitingForInput('Choose whether to load sample data.');
            }
            $ctx->setAnswer('sample_data', true);
        }

        $log = [];
        try {
            foreach ($this->commands as $args) {
                $output = $this->runner->runOrFail($args);
                $log[]  = trim($output);
            }

            return SetupStepResult::ok('Sample data loaded.', $log);
        } catch (Throwable $e) {
            return SetupStepResult::fail($e->getMessage(), $log);
        }
    }
}
