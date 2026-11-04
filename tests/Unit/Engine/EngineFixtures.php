<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Engine;

use Symfony\Component\Filesystem\Filesystem;

use function bin2hex;
use function chmod;
use function file_put_contents;
use function random_bytes;
use function sys_get_temp_dir;

final class EngineFixtures
{
    public const DB_CANARY = 'canary-db-0000';

    public static function tempDir(string $prefix): string
    {
        $dir = sys_get_temp_dir() . '/' . $prefix . bin2hex(random_bytes(6));
        (new Filesystem())->mkdir($dir);

        return $dir;
    }

    public static function script(string $dir, string $name, string $body): string
    {
        $path = $dir . '/' . $name;
        file_put_contents($path, "#!/bin/sh\n" . $body . "\n");
        chmod($path, 0o755);

        return $path;
    }

    /**
     * A `mysql` client stand-in that answers `--version` like Oracle mysql 8.4.13 (EN-M1 probe), then runs $body.
     */
    public static function client(string $dir, string $name, string $body, string $version = 'mysql  Ver 8.4.13 for Linux on x86_64 (MySQL Community Server - GPL)'): string
    {
        return self::script($dir, $name, 'if [ "$1" = "--version" ]; then echo "' . $version . '"; exit 0; fi' . "\n" . $body);
    }

    /**
     * A mysqldump stand-in: `--version` prints $version; otherwise writes "-- args: …" and a fake statement to the
     * --result-file (or stdout), and records MYSQL_PWD separately so tests can assert it never reached argv.
     */
    public static function fakeMysqldump(string $dir, string $version = 'mysqldump  Ver 9.4.0 for Linux on x86_64 (MySQL Community Server - GPL)', int $exit = 0): string
    {
        return self::script($dir, 'fake-mysqldump', <<<SH
            if [ "\$1" = "--version" ]; then echo "{$version}"; exit 0; fi
            out=""
            for a in "\$@"; do case "\$a" in --result-file=*) out="\${a#--result-file=}";; esac; done
            if [ -n "\$out" ]; then exec 1>"\$out"; fi
            echo "-- args: \$*"
            echo "CREATE TABLE t (id INT);"
            echo "\$MYSQL_PWD" > "{$dir}/last-pwd"
            if [ {$exit} -ne 0 ]; then echo "mysqldump: Got error: 1045 for mysql://app:REDACTED-TEST@db/x" >&2; fi
            exit {$exit}
            SH);
    }
}
