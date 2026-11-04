<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Command;

use Nowo\SiteBackupBundle\Backup\BackupArchiver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function is_numeric;
use function sprintf;

/**
 * Removes staging directories left in `backup.work_dir` by killed backup runs (1.28+).
 */
#[AsCommand(name: 'nowo:site-backup:cleanup', description: 'Remove leftover staging directories of killed backup runs from backup.work_dir')]
final class CleanupCommand extends Command
{
    public function __construct(private readonly BackupArchiver $archiver)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('older-than', null, InputOption::VALUE_REQUIRED, 'Only directories older than this many seconds', '3600');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io        = new SymfonyStyle($input, $output);
        $olderThan = $input->getOption('older-than');
        if (!is_numeric($olderThan) || (int) $olderThan < 0) {
            $io->error('--older-than must be a number of seconds.');

            return Command::INVALID;
        }

        // @igor-ignore - Removes files on disk; the archiver keeps no state between calls.
        $removed = $this->archiver->removeStaleWorkDirectories((int) $olderThan);
        $io->success(sprintf('%d leftover staging director%s removed.', $removed, $removed === 1 ? 'y' : 'ies'));

        return Command::SUCCESS;
    }
}
