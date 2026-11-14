<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Command;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use InvalidArgumentException;
use Nowo\SiteBackupBundle\Exception\ChainBrokenException;
use Nowo\SiteBackupBundle\Exception\ChainRestoreException;
use Nowo\SiteBackupBundle\Incremental\ChainRestoreReport;
use Nowo\SiteBackupBundle\Incremental\ChainVerifier;
use Nowo\SiteBackupBundle\Incremental\IncrementalBackupManagerInterface;
use Nowo\SiteBackupBundle\Log\SecretRedactor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

use function implode;
use function is_string;
use function sprintf;

use const DATE_ATOM;

/**
 * Restores a backup chain (1.36+): the full backup, its incrementals' binary logs up to `--until` and their file
 * deltas. `--check` verifies the chain metadata only and changes nothing.
 */
#[AsCommand(name: 'nowo:site-backup:restore-chain', description: 'Restore a full backup and its incrementals (point in time with --until)')]
final class RestoreChainCommand extends Command
{
    public function __construct(private readonly IncrementalBackupManagerInterface $manager)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('head', InputArgument::REQUIRED, 'Id of the last backup of the chain to restore (an incremental, or the full backup)')
            ->addOption('until', null, InputOption::VALUE_REQUIRED, 'Restore the database up to this time, inclusive (ISO 8601; no offset = UTC)')
            ->addOption('actor', null, InputOption::VALUE_REQUIRED, 'Who triggered the restore', 'cli')
            ->addOption('check', null, InputOption::VALUE_NONE, 'Only resolve and check the chain metadata; change nothing')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Required to restore: the database and the files are overwritten');
        PasswordInput::configure($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io   = new SymfonyStyle($input, $output);
        $head = (string) $input->getArgument('head');

        try {
            $until = $this->until($input->getOption('until'));
            $chain = $this->manager->resolveChain($head);
        } catch (InvalidArgumentException|ChainBrokenException $e) {
            $io->error(SecretRedactor::redact($e->getMessage()));

            return Command::INVALID;
        }
        $io->writeln('Chain: ' . implode(' → ', $chain->ids()));

        if ((bool) $input->getOption('check')) {
            $problems = ChainVerifier::verify($chain->links);
            if ($problems !== []) {
                $io->listing($problems);
                $io->error('The chain is not restorable.');

                return Command::FAILURE;
            }
            $io->success('The chain metadata is consistent (the archives are verified when restoring).');

            return Command::SUCCESS;
        }
        if (!(bool) $input->getOption('force')) {
            $io->error('The restore overwrites the database and the files: run again with --force (or --check to verify only).');

            return Command::INVALID;
        }

        try {
            $password = PasswordInput::read($input);
        } catch (InvalidArgumentException $e) {
            $io->error(SecretRedactor::redact($e->getMessage()));

            return Command::INVALID;
        }

        try {
            $report = $this->manager->restoreChain($head, $until, (string) $input->getOption('actor'), $password);
        } catch (ChainRestoreException $e) {
            $this->render($io, $e->getReport());
            $io->error('The restore STOPPED half-way: the site is NOT consistent. ' . SecretRedactor::redact((string) $e->getReport()->error));

            return Command::FAILURE;
        } catch (Throwable $e) {
            $io->error(SecretRedactor::redact($e->getMessage()) . ' Nothing was changed.');

            return Command::FAILURE;
        } finally {
            $password?->wipe();
        }

        $this->render($io, $report);
        $io->success(sprintf('Chain restored%s. Clear the cache and restart the PHP workers.', $until instanceof DateTimeImmutable ? ' up to ' . $until->format(DATE_ATOM) : ''));

        return Command::SUCCESS;
    }

    private function render(SymfonyStyle $io, ChainRestoreReport $report): void
    {
        $rows = [];
        foreach ($report->steps as $step) {
            $rows[] = [$step->backupId, $step->part, $step->status, $step->detail];
        }
        $io->table(['Backup', 'Part', 'Status', 'Detail'], $rows);
        foreach ($report->notes as $note) {
            $io->note($note);
        }
    }

    private function until(mixed $raw): ?DateTimeImmutable
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        try {
            return (new DateTimeImmutable($raw, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));
        } catch (Exception) {
            throw new InvalidArgumentException('--until must be a date and time (ISO 8601).');
        }
    }
}
