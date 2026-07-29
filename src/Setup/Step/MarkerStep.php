<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Setup\Step;

use Nowo\SiteBackupBundle\Setup\SetupContext;
use Nowo\SiteBackupBundle\Setup\SetupStepInput;
use Nowo\SiteBackupBundle\Setup\SetupStepResult;
use Nowo\SiteBackupBundle\Setup\Storage\SetupMarkerManager;

final class MarkerStep extends AbstractSetupStep
{
    public function __construct(
        string $id,
        string $label,
        private readonly SetupMarkerManager $markers,
        private readonly bool $writeDone = true,
    ) {
        parent::__construct($id, $label);
    }

    public function run(SetupContext $ctx, SetupStepInput $input): SetupStepResult
    {
        if ($this->writeDone) {
            $this->markers->markDone();
        }

        return SetupStepResult::ok('Setup markers updated (setup.done).');
    }
}
