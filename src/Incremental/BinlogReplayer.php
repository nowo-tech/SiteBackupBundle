<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Incremental;

use DateTimeImmutable;
use InvalidArgumentException;
use Nowo\SiteBackupBundle\Backup\PrivateTempDirectory;
use Nowo\SiteBackupBundle\Database\DumpImporter;
use Nowo\SiteBackupBundle\Database\MysqlDumpCommandFactory;
use Nowo\SiteBackupBundle\Database\MysqlOptionFile;
use Nowo\SiteBackupBundle\Exception\BinlogUnavailableException;
use Nowo\SiteBackupBundle\Process\ProcessDeadline;
use SensitiveParameter;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

use function basename;
use function fclose;
use function fopen;
use function sprintf;

/**
 * Replays captured binary logs into the configured database (1.36+, chain restores).
 *
 * 1. {@see self::decode()} (raw captures): `mysqlbinlog --skip-gtids --start-position=<full backup position>
 *    [--stop-datetime=<until + 1 s>]` over the local files, `TZ=UTC`, into a staging file — no `--database`: the
 *    chain restore checks every event with {@see BinlogSchemaFilter} instead (PP-M1 / PP-M2);
 * 2. {@see self::apply()}: the `mysql` client with `--binary-mode` (row images may hold any byte; it also disables the
 *    client commands but `delimiter` / `charset`), credentials in an option file, the decoded file on stdin.
 */
final class BinlogReplayer
{
    /**
     * @param list<string> $tlsOptions `--ssl-*` / `--tls-*` options for the option file (PP-L6)
     */
    public function __construct(
        #[SensitiveParameter]
        private readonly ?string $databaseUrl,
        private readonly MysqlBinlogTool $tool = new MysqlBinlogTool(),
        private readonly string $clientBinary = 'mysql',
        private readonly int $timeoutSeconds = 600,
        private readonly ?string $workDir = null,
        private readonly Filesystem $filesystem = new Filesystem(),
        private readonly array $tlsOptions = [],
    ) {
    }

    /**
     * The objects of the target schema (`TYPE name`), null when it does not exist (PP-H1 pre-flight).
     *
     * @return list<string>|null
     */
    public function schemaObjects(): ?array
    {
        return $this->server()->schemaObjects($this->databaseName());
    }

    /**
     * Creates the target schema; with $replace, drops and re-creates it (`--recreate-database`).
     */
    public function createSchema(bool $replace): void
    {
        $this->server()->createSchema($this->databaseName(), $replace);
    }

    private function server(): MysqlClientBinlogServer
    {
        return new MysqlClientBinlogServer($this->databaseUrl, $this->clientBinary, $this->timeoutSeconds, $this->workDir, $this->filesystem, $this->tlsOptions);
    }

    /**
     * @throws BinlogUnavailableException when the URL is not usable
     */
    public function databaseName(): string
    {
        try {
            return MysqlDumpCommandFactory::databaseName((string) $this->databaseUrl);
        } catch (InvalidArgumentException $e) {
            throw new BinlogUnavailableException('Binary log replay: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @param list<string> $files local binary logs (raw captures), oldest first
     * @param BinlogCoordinates $start where the full backup's snapshot ends; applies when the first file is its file
     * @param DateTimeImmutable|null $until last second to replay (inclusive); null = everything
     * @param (callable(): void)|null $tick
     */
    public function decode(array $files, BinlogCoordinates $start, ?DateTimeImmutable $until, string $target, ?callable $tick = null): void
    {
        $this->tool->assertUsable();
        $position = $files !== [] && basename($files[0]) === $start->file ? $start->position : null;
        $this->tool->decode($files, $target, $position, null, $until?->modify('+1 second'), false, $tick);
    }

    /**
     * @param (callable(): void)|null $tick
     *
     * @throws BinlogUnavailableException
     */
    public function apply(string $decodedFile, ?callable $tick = null): void
    {
        $database = $this->databaseName();
        $dir      = PrivateTempDirectory::createOwned('nowo-site-backup-replay-', $this->workDir);
        $input    = null;

        try {
            try {
                $optionFile = MysqlOptionFile::write((string) $this->databaseUrl, $dir, $this->tlsOptions);
            } catch (InvalidArgumentException $e) {
                throw new BinlogUnavailableException('Binary log replay: ' . $e->getMessage(), 0, $e);
            }
            $input = @fopen($decodedFile, 'r');
            if ($input === false) {
                throw new BinlogUnavailableException('The decoded binary logs are not readable.');
            }

            $process = new Process([$this->clientBinary, '--defaults-file=' . $optionFile, '--binary-mode', '--batch', '--', $database]);
            $process->setInput($input);
            try {
                ProcessDeadline::run($process, $this->timeoutSeconds, $tick);
            } catch (ProcessTimedOutException) {
                throw new BinlogUnavailableException(sprintf('The binary log replay timed out after %d seconds.', $this->timeoutSeconds));
            }
            if (!$process->isSuccessful()) {
                throw new BinlogUnavailableException('The binary log replay failed: ' . DumpImporter::safeError($process->getErrorOutput()));
            }
        } finally {
            if ($input !== null && $input !== false) {
                fclose($input);
            }
            $this->filesystem->remove($dir);
        }
    }
}
