<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Integration\RealClient;

use Nowo\SiteBackupBundle\Database\DumpImporter;
use Nowo\SiteBackupBundle\Exception\DumpFailedException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

use function bin2hex;
use function file_put_contents;
use function getenv;
use function is_string;
use function parse_url;
use function random_bytes;
use function rawurldecode;
use function trim;

/**
 * EN-M1 against the REAL Oracle mysql 8.4 and MariaDB 11.x client binaries (CI job "Real MySQL clients").
 *
 * Environment: SBB_REAL_DATABASE_URL (a MySQL 8.4 server), SBB_REAL_SHARED_DIR (a directory the client wrappers mount
 * at the same path) and SBB_REAL_CLIENT_ORACLE / SBB_REAL_CLIENT_MARIADB (client executables or wrappers). Skipped
 * when they are not set. A tampered dump whose first line is `\!`, `\T` or `source` must not run it.
 */
final class RealMysqlClientImportTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cases(): iterable
    {
        foreach (['oracle' => 'SBB_REAL_CLIENT_ORACLE', 'mariadb' => 'SBB_REAL_CLIENT_MARIADB'] as $flavour => $env) {
            foreach (['system', 'tee', 'source'] as $command) {
                yield $flavour . ' ' . $command => [$env, $command];
            }
        }
    }

    #[DataProvider('cases')]
    public function testClientCommandsInATamperedDumpNeverRun(string $clientEnv, string $command): void
    {
        [$client, $url, $shared] = $this->environment($clientEnv);
        $id                      = bin2hex(random_bytes(4));
        $marker                  = $shared . '/pwned-' . $command . '-' . $id;
        $table                   = 'sourced_' . $id;
        file_put_contents($shared . '/inc-' . $id . '.sql', 'CREATE TABLE ' . $table . " (id INT);\n");

        $first = match ($command) {
            'system' => '\\! touch ' . $marker,
            'tee'    => '\\T ' . $marker,
            default  => 'source ' . $shared . '/inc-' . $id . '.sql',
        };
        $dump = $shared . '/tampered-' . $id . '.sql';
        file_put_contents($dump, $first . "\nSELECT '<?php echo 1; ?>';\n\\t\n");

        try {
            (new DumpImporter($url, $client))->import($dump);
        } catch (DumpFailedException) {
            // Refusing the line is the expected outcome; the assertions below are what matters.
        }

        self::assertFileDoesNotExist($marker, 'the client command ran');
        self::assertSame('', $this->query($client, $url, "SHOW TABLES LIKE '" . $table . "'"), '`source` ran');
    }

    /**
     * Control: a genuine dump imports with both clients (the safety options do not break imports).
     */
    public function testAGenuineDumpImportsWithBothClients(): void
    {
        foreach (['SBB_REAL_CLIENT_ORACLE', 'SBB_REAL_CLIENT_MARIADB'] as $env) {
            [$client, $url, $shared] = $this->environment($env);
            $table                   = 'genuine_' . bin2hex(random_bytes(4));
            $dump                    = $shared . '/genuine-' . $table . '.sql';
            file_put_contents($dump, 'CREATE TABLE ' . $table . " (id INT);\nINSERT INTO " . $table . " VALUES (1);\n");

            (new DumpImporter($url, $client))->import($dump);

            self::assertSame($table, $this->query($client, $url, "SHOW TABLES LIKE '" . $table . "'"));
        }
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function environment(string $clientEnv): array
    {
        $client = getenv($clientEnv);
        $url    = getenv('SBB_REAL_DATABASE_URL');
        $shared = getenv('SBB_REAL_SHARED_DIR');
        if (!is_string($client) || $client === '' || !is_string($url) || $url === '' || !is_string($shared) || $shared === '') {
            self::markTestSkipped('Real client test: set SBB_REAL_DATABASE_URL, SBB_REAL_SHARED_DIR and ' . $clientEnv . '.');
        }
        (new Filesystem())->mkdir($shared);

        return [$client, $url, $shared];
    }

    private function query(string $client, string $url, string $sql): string
    {
        $parts   = (array) parse_url($url);
        $process = new Process(
            [$client, '--host=' . (string) ($parts['host'] ?? '127.0.0.1'), '--port=' . (string) ($parts['port'] ?? 3306), '--user=' . rawurldecode((string) ($parts['user'] ?? '')), '--batch', '--skip-column-names', '--execute=' . $sql, '--', trim((string) ($parts['path'] ?? ''), '/')],
            null,
            ['MYSQL_PWD' => rawurldecode((string) ($parts['pass'] ?? ''))],
        );
        $process->mustRun();

        return trim($process->getOutput());
    }
}
