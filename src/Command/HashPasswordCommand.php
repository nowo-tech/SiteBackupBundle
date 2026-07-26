<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function is_string;
use function password_hash;

use const PASSWORD_DEFAULT;

#[AsCommand(name: 'nowo:site-backup:hash-password', description: 'Hash a panel password for nowo_site_backup.security.password_hash')]
final class HashPasswordCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument('password', InputArgument::OPTIONAL, 'Plain password (prompted if omitted)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io       = new SymfonyStyle($input, $output);
        $password = $input->getArgument('password');
        if (!is_string($password) || $password === '') {
            $password = $io->askHidden('Password');
        }
        if (!is_string($password) || $password === '') {
            $io->error('Password is required.');

            return Command::FAILURE;
        }

        $io->writeln(password_hash($password, PASSWORD_DEFAULT));

        return Command::SUCCESS;
    }
}
