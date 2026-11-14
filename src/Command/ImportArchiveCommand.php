<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Command;

use InvalidArgumentException;
use JsonException;
use Nowo\SiteBackupBundle\Incremental\IncrementalBackupManagerInterface;
use Nowo\SiteBackupBundle\Log\SecretRedactor;
use Nowo\SiteBackupBundle\Model\BackupArtifact;
use Nowo\SiteBackupBundle\Model\ImportOptions;
use Nowo\SiteBackupBundle\Service\SiteBackupManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

use function array_map;
use function array_values;
use function count;
use function file_get_contents;
use function implode;
use function is_array;
use function is_bool;
use function is_file;
use function is_string;
use function json_decode;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * Imports an archive made elsewhere into `storage_dir` (1.29+); restore it afterwards with nowo:site-backup:restore.
 */
#[AsCommand(name: 'nowo:site-backup:import', description: 'Import a backup archive (zip, tar.gz, sql.gz; zip may be AES-256 encrypted) into storage_dir')]
final class ImportArchiveCommand extends Command
{
    public function __construct(private readonly SiteBackupManagerInterface $manager)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED | InputArgument::IS_ARRAY, 'Archive to import; 1.36+: a chain is the full backup then its incrementals, oldest first')
            ->addOption('actor', null, InputOption::VALUE_REQUIRED, 'Who imported it', 'cli')
            ->addOption('sidecar', null, InputOption::VALUE_REQUIRED, 'The archive\'s .backup.json / .meta.json (its signature)')
            ->addOption('require-signature', null, InputOption::VALUE_NEGATABLE, 'Require a valid signed sidecar (default: backup.signing.required)')
            ->addOption('allow-plaintext', null, InputOption::VALUE_NONE, 'With a password, also accept an archive that is not encrypted')
            ->addOption('keep-after-restore', null, InputOption::VALUE_NONE, 'Keep the plaintext copy after it has been restored');
        PasswordInput::configure($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $password = PasswordInput::read($input);
        } catch (InvalidArgumentException $e) {
            $io->error(SecretRedactor::redact($e->getMessage()));

            return Command::INVALID;
        }

        $sidecar     = null;
        $sidecarPath = $input->getOption('sidecar');
        if (is_string($sidecarPath) && $sidecarPath !== '') {
            try {
                $data = is_file($sidecarPath) ? json_decode((string) file_get_contents($sidecarPath), true, 32, JSON_THROW_ON_ERROR) : null;
            } catch (JsonException) {
                $data = null;
            }
            if (!is_array($data)) {
                $io->error('--sidecar must be a readable JSON sidecar.');

                return Command::INVALID;
            }
            /** @var array<string, mixed> $data */
            $sidecar = $data;
        }
        $require = $input->getOption('require-signature');
        $options = new ImportOptions(
            allowPlaintext: (bool) $input->getOption('allow-plaintext'),
            sidecar: $sidecar,
            requireSignature: is_bool($require) ? $require : null,
            deleteAfterRestore: !(bool) $input->getOption('keep-after-restore'),
        );

        $files = $input->getArgument('file');
        $files = is_array($files) ? array_values(array_map('strval', $files)) : [(string) $files];

        try {
            if (count($files) > 1) {
                if (!$this->manager instanceof IncrementalBackupManagerInterface) {
                    throw new InvalidArgumentException('Importing a chain needs the bundle\'s SiteBackupManager.');
                }
                $chain    = $this->manager->importChain($files, $password, (string) $input->getOption('actor'), $options);
                $artifact = $chain[count($chain) - 1];
                $io->writeln('Chain: ' . implode(' → ', array_map(static fn (BackupArtifact $a): string => $a->getId(), $chain)));
            } else {
                $artifact = $this->manager->importArchive($files[0], $password, (string) $input->getOption('actor'), $options);
            }
        } catch (Throwable $e) {
            $io->error(SecretRedactor::redact($e->getMessage()));

            return Command::FAILURE;
        } finally {
            $password?->wipe();
        }

        foreach ($artifact->getWarnings() as $warning) {
            $io->warning($warning);
        }
        $command = count($files) > 1 ? 'nowo:site-backup:restore-chain' : 'nowo:site-backup:restore';
        $io->success(sprintf('Imported as backup %s (plaintext tar.gz, owner-only). Restore it with %s %s%s.', $artifact->getId(), $command, $artifact->getId(), $options->deleteAfterRestore ? ' (it is deleted after the restore)' : ', then delete it'));

        return Command::SUCCESS;
    }
}
