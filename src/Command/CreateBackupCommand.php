<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Command;

use InvalidArgumentException;
use Nowo\SiteBackupBundle\Enum\ArchiveFormat;
use Nowo\SiteBackupBundle\Enum\TableRule;
use Nowo\SiteBackupBundle\Exception\BackupAlreadyRunningException;
use Nowo\SiteBackupBundle\Exception\BinlogFilterException;
use Nowo\SiteBackupBundle\Exception\BinlogGapException;
use Nowo\SiteBackupBundle\Exception\InvalidBackupOptionsException;
use Nowo\SiteBackupBundle\Incremental\IncrementalBackupManagerInterface;
use Nowo\SiteBackupBundle\Log\SecretRedactor;
use Nowo\SiteBackupBundle\Model\BackupOptions;
use Nowo\SiteBackupBundle\Service\SiteBackupManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

use function array_values;
use function is_array;
use function is_numeric;
use function is_string;
use function json_encode;
use function sprintf;

use const JSON_INVALID_UTF8_SUBSTITUTE;
use const JSON_UNESCAPED_SLASHES;

/**
 * Creates a backup (host cron recipe). 1.28+ options mirror {@see BackupOptions}; exit code 75 (EX_TEMPFAIL) when
 * another backup or restore is running.
 */
#[AsCommand(name: 'nowo:site-backup:create', description: 'Create an integral site backup archive with integrity manifest')]
final class CreateBackupCommand extends Command
{
    public const EXIT_LOCKED = 75;

    /** 1.36+: the incremental cannot continue its chain (binlog gap, or events that cannot be attributed): take a full backup. */
    public const EXIT_BINLOG_GAP = 3;

    public function __construct(private readonly SiteBackupManagerInterface $manager)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('label', 'l', InputOption::VALUE_REQUIRED, 'Optional human label')
            ->addOption('actor', null, InputOption::VALUE_REQUIRED, 'Who created the backup', 'cli')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'tar.gz, zip or sql.gz (default: backup.default_format)')
            ->addOption('level', null, InputOption::VALUE_REQUIRED, 'Compression level 1-9 (0 = store; default: backup.compression_level)')
            ->addOption('no-database', null, InputOption::VALUE_NONE, 'Files only')
            ->addOption('no-files', null, InputOption::VALUE_NONE, 'Database only')
            ->addOption('path', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Project-relative path to include instead of backup.include_paths (repeatable)')
            ->addOption('skip-table', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Table left out of the dump (repeatable; needs backup.database.dumper: builtin)')
            ->addOption('structure-only', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Table dumped without rows (repeatable; needs the builtin dumper)')
            ->addOption('allow-unsafe-table-rules', null, InputOption::VALUE_NONE, 'Downgrade the foreign-key / view refusals of --skip-table to warnings')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print the result as JSON (id, file, size, sha256, format, warnings)')
            ->addOption('key-id', null, InputOption::VALUE_REQUIRED, 'Opaque id of the archive password in your key ring (recorded in the meta, 1.29+)')
            ->addOption('record-binlog', null, InputOption::VALUE_NONE, 'Record the binlog coordinates of the dump and start a chain (1.36+; builtin dumper, Oracle MySQL tools)')
            ->addOption('record-file-manifest', null, InputOption::VALUE_NONE, 'Record the file manifest and start a chain (1.36+)')
            ->addOption('routines', null, InputOption::VALUE_NONE, 'With --record-binlog: also dump stored routines and events (1.36+)')
            ->addOption('incremental-of', null, InputOption::VALUE_REQUIRED, 'Create an incremental backup after this backup (the head of its chain, 1.36+)');
        PasswordInput::configure($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $options  = $this->options($input);
            $artifact = $this->manager->createBackup($options);
        } catch (BackupAlreadyRunningException $e) {
            $io->warning(SecretRedactor::redact($e->getMessage()));

            return self::EXIT_LOCKED;
        } catch (BinlogGapException|BinlogFilterException $e) {
            // PP-D1-L2: a refused event, like a gap, needs a new full backup. Beacon counts promotions per reason: the
            // machine-readable code goes to stdout (`reason: <code>`, or JSON with --json) and to the history row.
            $io->error(SecretRedactor::redact($e->getMessage()) . ' Take a full backup.');
            $output->writeln((bool) $input->getOption('json')
                ? (string) json_encode(['error' => 'needs_full_backup', 'reason' => $e->getReason()])
                : 'reason: ' . $e->getReason());

            return self::EXIT_BINLOG_GAP;
        } catch (InvalidBackupOptionsException|InvalidArgumentException $e) {
            $io->error(SecretRedactor::redact($e->getMessage()));

            return Command::INVALID;
        } catch (Throwable $e) {
            $io->error(SecretRedactor::redact($e->getMessage()));

            return Command::FAILURE;
        }

        if ((bool) $input->getOption('json')) {
            $chain = [];
            if (is_string($artifact->getMeta()['type'] ?? null)) {
                // 1.36+: only for chain backups.
                $chain = ['type' => $artifact->getMeta()['type'], 'chain_id' => $artifact->getMeta()['chain_id'] ?? null, 'parent_id' => $artifact->getMeta()['parent_id'] ?? null];
            }
            $output->writeln((string) json_encode($chain + [
                'id'        => $artifact->getId(),
                'file'      => $artifact->getAbsolutePath(),
                'size'      => $artifact->getSizeBytes(),
                'sha256'    => $artifact->getArchiveSha256(),
                'format'    => $artifact->getFormat()->value,
                'content'   => $artifact->getContent(),
                'encrypted' => $artifact->isEncrypted(),
                'warnings'  => $artifact->getWarnings(),
            ], JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }

        foreach ($artifact->getWarnings() as $warning) {
            $io->warning($warning);
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

    private function options(InputInterface $input): BackupOptions
    {
        $label   = $input->getOption('label');
        $actor   = $input->getOption('actor');
        $default = $this->manager->defaultOptions(
            is_string($label) && $label !== '' ? $label : null,
            is_string($actor) && $actor !== '' ? $actor : null,
        );

        $format = $default->format;
        $raw    = $input->getOption('format');
        if (is_string($raw) && $raw !== '') {
            $format = ArchiveFormat::tryFrom($raw) ?? throw new InvalidBackupOptionsException('--format must be tar.gz, zip or sql.gz.');
        }

        $level = $default->compressionLevel;
        $raw   = $input->getOption('level');
        if ($raw !== null) {
            if (!is_numeric($raw)) {
                throw new InvalidBackupOptionsException('--level must be a number between 0 and 9.');
            }
            $level = (int) $raw;
        }

        $paths = $input->getOption('path');
        $paths = is_array($paths) && $paths !== [] ? array_values($paths) : null;

        $rules = [];
        foreach (['skip-table' => TableRule::Skip, 'structure-only' => TableRule::StructureOnly] as $option => $rule) {
            $tables = $input->getOption($option);
            foreach (is_array($tables) ? $tables : [] as $table) {
                $rules[(string) $table] = $rule;
            }
        }

        $parent = $input->getOption('incremental-of');
        if (is_string($parent) && $parent !== '') {
            if (!$this->manager instanceof IncrementalBackupManagerInterface) {
                throw new InvalidBackupOptionsException('--incremental-of needs the bundle\'s SiteBackupManager.');
            }
            if ($input->getOption('no-database') || $input->getOption('no-files') || $paths !== null || $rules !== [] || $input->getOption('record-binlog') || $input->getOption('record-file-manifest') || $input->getOption('routines')) {
                throw new InvalidBackupOptionsException('--incremental-of takes its content from the chain: --no-database, --no-files, --path, table rules and --record-* do not apply.');
            }

            return $this->manager->incrementalOptions($parent, new BackupOptions(
                format: $format,
                compressionLevel: $level,
                label: $default->label,
                createdBy: $default->createdBy,
                password: PasswordInput::read($input),
                keyId: is_string($input->getOption('key-id')) && $input->getOption('key-id') !== '' ? $input->getOption('key-id') : null,
            ));
        }

        return new BackupOptions(
            includeDatabase: !(bool) $input->getOption('no-database'),
            includeFiles: !(bool) $input->getOption('no-files') && $format !== ArchiveFormat::SqlGz,
            includePaths: $paths,
            tableRules: $rules,
            format: $format,
            compressionLevel: $level,
            label: $default->label,
            createdBy: $default->createdBy,
            allowUnsafeTableRules: (bool) $input->getOption('allow-unsafe-table-rules'),
            password: PasswordInput::read($input),
            keyId: is_string($input->getOption('key-id')) && $input->getOption('key-id') !== '' ? $input->getOption('key-id') : null,
            recordBinlogCoordinates: (bool) $input->getOption('record-binlog'),
            recordFileManifest: (bool) $input->getOption('record-file-manifest'),
            dumpRoutines: (bool) $input->getOption('routines'),
        );
    }
}
