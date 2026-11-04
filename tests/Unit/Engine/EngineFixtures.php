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

    /**
     * An age ≥ 1.3 stand-in with its batchpass plugin: reads the passphrase from fd 3 only, writes an age-like header
     * with the passphrase's SHA-256 (so a wrong passphrase fails), then the payload. A `slow` file next to it makes
     * every run sleep 1 s (to inspect /proc while it runs).
     */
    public static function fakeAge(string $dir, string $version = 'v1.3.1', bool $withPlugin = true): string
    {
        if ($withPlugin) {
            self::script($dir, 'age-plugin-batchpass', 'exit 0');
        }

        return self::script($dir, 'age', <<<SH
            if [ "\$1" = "--version" ]; then echo "{$version}"; exit 0; fi
            mode=\$1; shift
            out=""; in=""
            while [ \$# -gt 0 ]; do case "\$1" in -o) out="\$2"; shift 2;; --) in="\$2"; shift 2;; *) shift;; esac; done
            pass=\$(cat <&3)
            if [ -f "{$dir}/slow" ]; then sleep 1; fi
            if [ -f "{$dir}/broken" ]; then echo "age: error: something else" >&2; exit 3; fi
            h=\$(printf %s "\$pass" | sha256sum | cut -c1-64)
            if [ "\$mode" = "-e" ]; then { printf 'age-encryption.org/v1\\n-> scrypt fake 18\\n%s\\n--- x\\n' "\$h"; cat "\$in"; } > "\$out"; exit 0; fi
            want=\$(sed -n 3p "\$in")
            if [ "\$want" != "\$h" ]; then echo "age: error: incorrect passphrase" >&2; exit 1; fi
            tail -n +5 "\$in" > "\$out"
            SH);
    }
}
