<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Command;

use InvalidArgumentException;
use Nowo\SiteBackupBundle\Database\BuiltinMysqlDumper;
use Nowo\SiteBackupBundle\Database\MysqlClientSchemaInspector;
use Nowo\SiteBackupBundle\Database\MysqlDumpCommandFactory;
use Nowo\SiteBackupBundle\Database\TableSafetyChecker;
use Nowo\SiteBackupBundle\Enum\TableRule;
use Nowo\SiteBackupBundle\Exception\DumpFailedException;
use Nowo\SiteBackupBundle\Exception\InvalidBackupOptionsException;
use Nowo\SiteBackupBundle\Exception\UnsafeTableRuleException;
use Nowo\SiteBackupBundle\Model\BackupOptions;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

use function array_filter;
use function array_values;
use function is_array;
use function is_string;
use function preg_match;
use function sprintf;

/**
 * Built-in `backup.database_dump_command`: streams a MySQL / MariaDB dump of DATABASE_URL to stdout.
 *
 *     nowo_site_backup:
 *         backup:
 *             database_dump_command: 'php %kernel.project_dir%/bin/console nowo:site-backup:db-dump --no-debug'
 *
 * Nothing but SQL is written to stdout; diagnostics go to stderr.
 */
#[AsCommand(name: 'nowo:site-backup:db-dump', description: 'Dump the DATABASE_URL MySQL/MariaDB database to stdout (for backup.database_dump_command)')]
final class DatabaseDumpCommand extends Command
{
    /**
     * @param list<string> $requiredTables never skipped nor dumped without rows (1.28+)
     */
    public function __construct(
        private readonly ?string $databaseUrl = null,
        private readonly int $timeoutSeconds = 600,
        private readonly array $requiredTables = [],
        private readonly string $migrationsTable = TableSafetyChecker::DEFAULT_MIGRATIONS_TABLE,
        private readonly string $clientBinary = 'mysql',
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('url', null, InputOption::VALUE_REQUIRED, 'Database URL (default: DATABASE_URL)')
            ->addOption('binary', null, InputOption::VALUE_REQUIRED, 'mysqldump / mariadb-dump binary', 'mysqldump')
            ->addOption('skip-ssl-verify-server-cert', null, InputOption::VALUE_NONE, 'MariaDB client only: keep TLS but skip server certificate verification (self-signed MySQL on a private network)')
            ->addOption('option', 'o', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Extra mysqldump option (repeatable), e.g. -o=--column-statistics=0')
            ->addOption('ignore-table', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Table left out of the dump entirely (repeatable, 1.28+)')
            ->addOption('structure-only', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Table dumped without rows (repeatable, 1.28+)')
            ->addOption('allow-unsafe-table-rules', null, InputOption::VALUE_NONE, 'Downgrade the foreign-key / view refusals of --ignore-table to warnings');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $stderr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        $url    = $input->getOption('url');
        $url    = is_string($url) && $url !== '' ? $url : (string) $this->databaseUrl;
        $binary = $input->getOption('binary');
        $extra  = $input->getOption('option');

        try {
            $spec = MysqlDumpCommandFactory::fromDatabaseUrl(
                $url,
                (bool) $input->getOption('skip-ssl-verify-server-cert'),
                is_string($binary) && $binary !== '' ? $binary : 'mysqldump',
                is_array($extra) ? array_values(array_filter($extra, is_string(...))) : [],
            );
        } catch (InvalidArgumentException $e) {
            $stderr->writeln('nowo:site-backup:db-dump: ' . $e->getMessage());

            return Command::INVALID;
        }

        try {
            $rules = $this->tableRules($input);
            if ($rules !== []) {
                $inspector = new MysqlClientSchemaInspector($url, $this->clientBinary, (bool) $input->getOption('skip-ssl-verify-server-cert'), $this->timeoutSeconds);
                foreach (TableSafetyChecker::check($rules, $inspector->inspect(), $this->requiredTables, (bool) $input->getOption('allow-unsafe-table-rules'), $this->migrationsTable) as $warning) {
                    $stderr->writeln('nowo:site-backup:db-dump: warning: ' . $warning);
                }
            }
        } catch (InvalidBackupOptionsException|UnsafeTableRuleException|DumpFailedException $e) {
            $stderr->writeln('nowo:site-backup:db-dump: ' . $e->getMessage());

            return Command::INVALID;
        }

        $commands = BuiltinMysqlDumper::planCommands($spec['command'], MysqlDumpCommandFactory::databaseName($url), $rules, false);
        foreach ($commands as $command) {
            $code = $this->stream($command, $spec['env'], $output, $stderr);
            if ($code !== Command::SUCCESS) {
                return $code;
            }
        }

        return Command::SUCCESS;
    }

    /**
     * @return array<string, TableRule>
     */
    private function tableRules(InputInterface $input): array
    {
        $rules = [];
        foreach (['ignore-table' => TableRule::Skip, 'structure-only' => TableRule::StructureOnly] as $option => $rule) {
            $values = $input->getOption($option);
            foreach (is_array($values) ? $values : [] as $table) {
                if (!is_string($table) || preg_match(BackupOptions::TABLE_NAME_PATTERN, $table) !== 1) {
                    throw new InvalidBackupOptionsException('Table names must match [A-Za-z0-9_$]{1,64}.');
                }
                $rules[$table] = $rule;
            }
        }

        return $rules;
    }

    /**
     * @param list<string> $command
     * @param array<string, string> $env
     */
    private function stream(array $command, array $env, OutputInterface $output, OutputInterface $stderr): int
    {
        // The password stays in MYSQL_PWD (env), never in argv nor in the messages below.
        // REQ-RUNTIME-001: hard + idle timeout. mysqldump spawns no children, so Process::stop() on expiry is enough;
        // run() keeps the blocking pipe reads a large streamed dump needs.
        $process = new Process($command, null, $env, null, (float) $this->timeoutSeconds);
        $process->setIdleTimeout((float) $this->timeoutSeconds);

        try {
            $process->run(static function (string $type, string $buffer) use ($output, $stderr): void {
                if ($type === Process::OUT) {
                    $output->write($buffer, false, OutputInterface::OUTPUT_RAW);

                    return;
                }
                $stderr->write($buffer, false, OutputInterface::OUTPUT_RAW);
            });
        } catch (ProcessTimedOutException) {
            $stderr->writeln(sprintf('nowo:site-backup:db-dump: dump timed out after %d seconds (process_timeout).', $this->timeoutSeconds));

            return Command::FAILURE;
        }

        $code = $process->getExitCode() ?? 1;

        return $code === 0 ? Command::SUCCESS : ($code > 0 && $code < 256 ? $code : Command::FAILURE);
    }
}
