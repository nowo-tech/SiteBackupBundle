<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Command;

use Nowo\SiteBackupBundle\Service\SiteBackupManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function substr;

use const DATE_ATOM;

#[AsCommand(name: 'nowo:site-backup:list', description: 'List site backup archives')]
final class ListBackupsCommand extends Command
{
    public function __construct(private readonly SiteBackupManager $manager)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $backups = $this->manager->listBackups();
        if ($backups === []) {
            $io->warning('No backups found.');

            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($backups as $b) {
            $rows[] = [
                $b->getId(),
                $b->getCreatedAt()->format(DATE_ATOM),
                (string) $b->getSizeBytes(),
                $b->getLabel() ?? '',
                substr($b->getArchiveSha256(), 0, 12) . '…',
            ];
        }
        $io->table(['ID', 'Created', 'Bytes', 'Label', 'SHA-256'], $rows);

        return Command::SUCCESS;
    }
}
