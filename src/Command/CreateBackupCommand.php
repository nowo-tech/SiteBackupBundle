<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Command;

use JsonException;
use Nowo\SiteBackupBundle\Enum\ArchiveFormat;
use Nowo\SiteBackupBundle\Enum\TableRule;
use Nowo\SiteBackupBundle\Exception\BackupAlreadyRunningException;
use Nowo\SiteBackupBundle\Exception\InvalidBackupOptionsException;
use Nowo\SiteBackupBundle\Model\BackupOptions;
use Nowo\SiteBackupBundle\Service\SiteBackupManager;
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

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * Creates a backup (host cron recipe). 1.28+ options mirror {@see BackupOptions}; exit code 75 (EX_TEMPFAIL) when
 * another backup or restore is running.
 */
#[AsCommand(name: 'nowo:site-backup:create', description: 'Create an integral site backup archive with integrity manifest')]
final class CreateBackupCommand extends Command
{
    public const EXIT_LOCKED = 75;

    public function __construct(private readonly SiteBackupManager $manager)
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
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print the result as JSON (id, file, size, sha256, format, warnings)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $options  = $this->options($input);
            $artifact = $this->manager->createBackup($options);
        } catch (BackupAlreadyRunningException $e) {
            $io->warning($e->getMessage());

            return self::EXIT_LOCKED;
        } catch (InvalidBackupOptionsException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        } catch (Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if ((bool) $input->getOption('json')) {
            try {
                $output->writeln(json_encode([
                    'id'       => $artifact->getId(),
                    'file'     => $artifact->getAbsolutePath(),
                    'size'     => $artifact->getSizeBytes(),
                    'sha256'   => $artifact->getArchiveSha256(),
                    'format'   => $artifact->getFormat()->value,
                    'content'  => $artifact->getContent(),
                    'warnings' => $artifact->getWarnings(),
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            } catch (JsonException $e) {
                $io->error($e->getMessage());

                return Command::FAILURE;
            }

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
        );
    }
}
