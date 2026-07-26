<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Command;

use Nowo\SiteBackupBundle\Service\SiteBackupManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function count;
use function sprintf;

#[AsCommand(name: 'nowo:site-backup:verify', description: 'Verify backup archive and MANIFEST checksums')]
final class VerifyBackupCommand extends Command
{
    public function __construct(private readonly SiteBackupManager $manager)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED, 'Backup id');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $result = $this->manager->verifyBackup((string) $input->getArgument('id'));
        if ($result['ok']) {
            $io->success(sprintf('Integrity OK (%d files).', count($result['checksums'])));

            return Command::SUCCESS;
        }

        $io->error('Integrity failed:');
        foreach ($result['errors'] as $error) {
            $io->writeln(' - ' . $error);
        }

        return Command::FAILURE;
    }
}
