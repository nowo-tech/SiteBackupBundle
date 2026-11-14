<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Command;

use Nowo\SiteBackupBundle\Incremental\BinlogPrerequisitesInterface;
use Nowo\SiteBackupBundle\Incremental\Check;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function strtoupper;

/**
 * Checks the binary log incremental prerequisites (1.36+): exit 0 when nothing fails (warnings allowed).
 */
#[AsCommand(name: 'nowo:site-backup:binlog-check', description: 'Check the MySQL prerequisites of binary log incremental backups')]
final class BinlogCheckCommand extends Command
{
    public function __construct(private readonly BinlogPrerequisitesInterface $prerequisites)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $checks = $this->prerequisites->check();
        $rows   = [];
        foreach ($checks as $check) {
            $rows[] = [$check->name, strtoupper($check->status), $check->detail];
        }
        $io->table(['Check', 'Status', 'Detail'], $rows);

        if (!Check::allPass($checks)) {
            $io->error('Binary log incrementals are not usable on this host yet.');

            return Command::FAILURE;
        }
        $io->success('Binary log incrementals can run here.');

        return Command::SUCCESS;
    }
}
