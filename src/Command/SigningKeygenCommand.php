<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Command;

use Nowo\SiteBackupBundle\Signing\BackupSigner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Prints a new Ed25519 key pair for `backup.signing` (1.29+). Store the secret in your secrets manager / env, never
 * in the project or a backup; publish the public key to every verifier.
 */
#[AsCommand(name: 'nowo:site-backup:signing-keygen', description: 'Generate an Ed25519 key pair for backup signatures (secret goes to your secrets manager)')]
final class SigningKeygenCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $pair = BackupSigner::generateKeyPair();
        $output->writeln('SITE_BACKUP_SIGNING_KEY=' . $pair['secret_key']);
        $output->writeln('public_key: ' . $pair['public_key']);

        return Command::SUCCESS;
    }
}
