<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Command;

use Nowo\SiteBackupBundle\Database\DumpImporter;
use Nowo\SiteBackupBundle\Exception\BackupAlreadyRunningException;
use Nowo\SiteBackupBundle\Exception\DumpFailedException;
use Nowo\SiteBackupBundle\Log\SecretRedactor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Streams a SQL dump into the configured database through the `mysql` client (1.28+): constant memory, password
 * in the environment. It overwrites data, so it needs --force.
 */
#[AsCommand(name: 'nowo:site-backup:db-import', description: 'Import a SQL dump into the configured database through the mysql client (streamed)')]
final class DatabaseImportCommand extends Command
{
    public function __construct(private readonly DumpImporter $importer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'SQL file (e.g. var/site-backup/last-restore-dump.sql)')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Required: the import overwrites the tables it contains');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!(bool) $input->getOption('force')) {
            $io->error('The import overwrites the tables of the dump: run again with --force.');

            return Command::INVALID;
        }

        try {
            $this->importer->import((string) $input->getArgument('file'));
        } catch (DumpFailedException|BackupAlreadyRunningException $e) {
            $io->error(SecretRedactor::redact($e->getMessage()));

            return Command::FAILURE;
        }

        $io->success('SQL dump imported.');

        return Command::SUCCESS;
    }
}
