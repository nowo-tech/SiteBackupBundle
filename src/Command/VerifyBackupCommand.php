<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Command;

use InvalidArgumentException;
use JsonException;
use Nowo\SiteBackupBundle\Backup\VerifyFileResult;
use Nowo\SiteBackupBundle\Model\ArchivePassword;
use Nowo\SiteBackupBundle\Service\SiteBackupManager;
use SensitiveParameter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function count;
use function file_get_contents;
use function is_array;
use function is_bool;
use function is_file;
use function is_string;
use function json_decode;
use function preg_match;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * Verifies a stored backup by id, or (1.29+) any archive file with `--file` (a download, another instance's backup).
 *
 * `--file` exit codes: 0 ok, 1 integrity failure (checksums, tampered or truncated data), 2 wrong / missing password,
 * 3 unsupported or corrupt, 4 signature missing (when required) or invalid, 5 not encrypted although a password was
 * given (downgrade).
 */
#[AsCommand(name: 'nowo:site-backup:verify', description: 'Verify a backup (by id, or any archive with --file) and its MANIFEST checksums')]
final class VerifyBackupCommand extends Command
{
    public function __construct(private readonly SiteBackupManager $manager)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('id', InputArgument::OPTIONAL, 'Backup id in storage_dir')
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'Verify this archive file instead (zip, tar.gz, sql.gz, .age)')
            ->addOption('sha256', null, InputOption::VALUE_REQUIRED, 'Expected SHA-256 of the archive file (--file)')
            ->addOption('sidecar', null, InputOption::VALUE_REQUIRED, 'Its .backup.json / .meta.json (--file): checksums and signature')
            ->addOption('allow-plaintext', null, InputOption::VALUE_NONE, 'With a password, also accept an archive that is not encrypted (--file)')
            ->addOption('require-signature', null, InputOption::VALUE_NEGATABLE, 'Require a valid signed sidecar (--file; default: backup.signing.required)');
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

        $file = $input->getOption('file');
        if (is_string($file) && $file !== '') {
            $code = $this->verifyFile($io, $file, $input, $password);
            $password?->wipe();

            return $code;
        }

        $id = $input->getArgument('id');
        if (!is_string($id) || $id === '') {
            $io->error('Give a backup id or --file=<archive>.');

            return Command::INVALID;
        }

        $result = $this->manager->verifyBackup($id, $password);
        $password?->wipe();
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

    private function verifyFile(SymfonyStyle $io, string $file, InputInterface $input, #[SensitiveParameter] ?ArchivePassword $password): int
    {
        $sha = $input->getOption('sha256');
        if (is_string($sha) && preg_match('/^[a-fA-F0-9]{64}$/', $sha) !== 1) {
            $io->error('--sha256 must be 64 hexadecimal characters.');

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
        $result  = $this->manager->verifyFile($file, $password, is_string($sha) ? $sha : null, $sidecar, (bool) $input->getOption('allow-plaintext'), is_bool($require) ? $require : null);

        if ($result->isOk()) {
            $io->success(sprintf('Integrity OK (%s%s, %d files).', $result->format !== null ? $result->format->value : '?', $result->encryption !== null ? ', ' . $result->encryption : '', count($result->checksums)));

            return VerifyFileResult::OK;
        }

        $io->error('Verification failed:');
        foreach ($result->errors as $error) {
            $io->writeln(' - ' . $error);
        }

        return $result->status;
    }
}
