<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Command;

use Nowo\SiteBackupBundle\Drill\RestoreDrill;
use Nowo\SiteBackupBundle\Log\SecretRedactor;
use Nowo\SiteBackupBundle\Model\BackupArtifact;
use Nowo\SiteBackupBundle\Service\SiteBackupManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

use function count;
use function is_numeric;
use function json_encode;
use function sprintf;

use const JSON_INVALID_UTF8_SUBSTITUTE;

/**
 * Restore drill (1.29+): imports a stored backup's dump into a throwaway schema, compares table statistics, drops the
 * schema. Exit 0 when the drill passes, 1 when tables differ, 2 on errors.
 */
#[AsCommand(name: 'nowo:site-backup:drill', description: 'Restore a backup into a throwaway schema and compare row counts / checksums')]
final class RestoreDrillCommand extends Command
{
    public function __construct(
        private readonly SiteBackupManagerInterface $manager,
        private readonly RestoreDrill $drill,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('id', InputArgument::REQUIRED, 'Backup id in storage_dir (created with recordTableStats)')
            ->addOption('schema', null, InputOption::VALUE_REQUIRED, 'Throwaway schema to create and drop', 'nowo_backup_drill')
            ->addOption('row-tolerance', null, InputOption::VALUE_REQUIRED, 'Accepted row difference per table', '0')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print the report as JSON');
        PasswordInput::configure($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io        = new SymfonyStyle($input, $output);
        $tolerance = $input->getOption('row-tolerance');
        $artifact  = $this->manager->getBackup((string) $input->getArgument('id'));
        if (!$artifact instanceof BackupArtifact || !is_numeric($tolerance) || (int) $tolerance < 0) {
            $io->error('Unknown backup id or invalid --row-tolerance.');

            return 2;
        }

        try {
            $password = PasswordInput::read($input);
            $report   = $this->drill->run($artifact, (string) $input->getOption('schema'), $password, (int) $tolerance);
            $password?->wipe();
        } catch (Throwable $e) {
            $io->error(SecretRedactor::redact($e->getMessage()));

            return 2;
        }

        if ((bool) $input->getOption('json')) {
            $output->writeln((string) json_encode($report->toArray(), JSON_INVALID_UTF8_SUBSTITUTE));
        } elseif ($report->passed) {
            $io->success(sprintf('Drill passed: %d tables match (%.1f s).', $report->tablesChecked, $report->durationSeconds));
        } else {
            $io->error(sprintf('Drill failed: %d of %d tables differ.', count($report->mismatches), $report->tablesChecked));
            foreach ($report->mismatches as $m) {
                $io->writeln(sprintf(' - %s: rows %s / %s, checksum %s / %s', $m['table'], $m['expected_rows'] ?? '-', $m['actual_rows'] ?? '-', $m['expected_checksum'] ?? '-', $m['actual_checksum'] ?? '-'));
            }
        }

        return $report->passed ? Command::SUCCESS : Command::FAILURE;
    }
}
