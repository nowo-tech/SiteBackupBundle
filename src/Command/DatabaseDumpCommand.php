<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Command;

use InvalidArgumentException;
use Nowo\SiteBackupBundle\Database\MysqlDumpCommandFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

use function array_filter;
use function array_values;
use function is_array;
use function is_string;

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
    public function __construct(
        private readonly ?string $databaseUrl = null,
        private readonly int $timeoutSeconds = 600,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('url', null, InputOption::VALUE_REQUIRED, 'Database URL (default: DATABASE_URL)')
            ->addOption('binary', null, InputOption::VALUE_REQUIRED, 'mysqldump / mariadb-dump binary', 'mysqldump')
            ->addOption('skip-ssl-verify-server-cert', null, InputOption::VALUE_NONE, 'MariaDB client only: keep TLS but skip server certificate verification (self-signed MySQL on a private network)')
            ->addOption('option', 'o', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Extra mysqldump option (repeatable), e.g. -o=--column-statistics=0');
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

            return 2;
        }

        $process = new Process($spec['command'], null, $spec['env'], null, (float) $this->timeoutSeconds);
        $process->run(static function (string $type, string $buffer) use ($output, $stderr): void {
            if ($type === Process::OUT) {
                $output->write($buffer, false, OutputInterface::OUTPUT_RAW);

                return;
            }
            $stderr->write($buffer, false, OutputInterface::OUTPUT_RAW);
        });

        $code = $process->getExitCode() ?? 1;

        return $code === 0 ? Command::SUCCESS : ($code > 0 && $code < 256 ? $code : Command::FAILURE);
    }
}
