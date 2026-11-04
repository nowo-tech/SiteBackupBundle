<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Command;

use InvalidArgumentException;
use Nowo\SiteBackupBundle\Service\SiteBackupManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

use function sprintf;

/**
 * Imports an archive made elsewhere into `storage_dir` (1.29+); restore it afterwards with nowo:site-backup:restore.
 */
#[AsCommand(name: 'nowo:site-backup:import', description: 'Import a backup archive (zip, tar.gz, sql.gz; zip may be AES-256 encrypted) into storage_dir')]
final class ImportArchiveCommand extends Command
{
    public function __construct(private readonly SiteBackupManager $manager)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'Archive to import')
            ->addOption('actor', null, InputOption::VALUE_REQUIRED, 'Who imported it', 'cli');
        PasswordInput::configure($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $password = PasswordInput::read($input);
        } catch (InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }

        try {
            $artifact = $this->manager->importArchive((string) $input->getArgument('file'), $password, (string) $input->getOption('actor'));
        } catch (Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        } finally {
            $password?->wipe();
        }

        foreach ($artifact->getWarnings() as $warning) {
            $io->warning($warning);
        }
        $io->success(sprintf('Imported as backup %s (plaintext tar.gz, owner-only). Restore it with nowo:site-backup:restore %s, then delete it.', $artifact->getId(), $artifact->getId()));

        return Command::SUCCESS;
    }
}
