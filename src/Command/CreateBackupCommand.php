<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Command;

use Nowo\SiteBackupBundle\Service\SiteBackupManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

use function sprintf;

#[AsCommand(name: 'nowo:site-backup:create', description: 'Create an integral site backup archive with integrity manifest')]
final class CreateBackupCommand extends Command
{
    public function __construct(private readonly SiteBackupManager $manager)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('label', 'l', InputOption::VALUE_REQUIRED, 'Optional human label')
            ->addOption('actor', null, InputOption::VALUE_REQUIRED, 'Who created the backup', 'cli');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $artifact = $this->manager->createBackup(
                $input->getOption('label') ? (string) $input->getOption('label') : null,
                (string) $input->getOption('actor'),
            );
        } catch (Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Backup %s created (%d bytes, sha256=%s)',
            $artifact->getId(),
            $artifact->getSizeBytes(),
            $artifact->getArchiveSha256(),
        ));
        $io->writeln($artifact->getAbsolutePath());

        return Command::SUCCESS;
    }
}
