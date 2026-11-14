<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Incremental;

use Nowo\SiteBackupBundle\Tests\Unit\Engine\EngineFixtures;

/**
 * Stand-ins for the Oracle client tools and the server (unit tests only; the real tools run in the CI job
 * "Binlog incrementals (MySQL 9)").
 */
final class BinlogFakes
{
    /**
     * A `mysql` client: answers the binlog statements, records its argv (`args`) and the option file it was given
     * (`cnf`) in $dir. Rows of `SHOW BINARY LOGS` come from `$dir/logs`; a `$dir/fail` file makes every statement fail.
     */
    public static function mysqlClient(string $dir, string $version = '9.7.2'): string
    {
        return EngineFixtures::script($dir, 'fake-mysql', <<<SH
            if [ "\$1" = "--version" ]; then echo "mysql  Ver {$version} for Linux on x86_64 (MySQL Community Server - GPL)"; exit 0; fi
            echo "\$*" >> "{$dir}/args"
            for a in "\$@"; do case "\$a" in --defaults-file=*) cat "\${a#--defaults-file=}" > "{$dir}/cnf"; echo "\${a#--defaults-file=}" > "{$dir}/cnf-path";; esac; done
            if [ -f "{$dir}/fail" ]; then echo "ERROR 1227 (42000) at line 1: Access denied; password=REDACTED-TEST" >&2; exit 1; fi
            case "\$*" in
              *"DEFAULT_CHARACTER_SET_NAME"*) printf 'utf8mb4\\tutf8mb4_0900_ai_ci\\n';;
              *"SELECT SCHEMA_NAME"*) if [ -f "{$dir}/schemata" ]; then cat "{$dir}/schemata"; fi;;
              *"information_schema.TABLES"*) if [ -f "{$dir}/objects" ]; then cat "{$dir}/objects"; fi;;
              *"CREATE DATABASE"*) echo "\$*" >> "{$dir}/ddl";;
              *"SHOW DATABASES"*) printf 'app\\nmysql\\n';;
              *"SELECT VERSION()"*) echo "{$version}";;
              *"server_uuid"*) echo "3E11FA47-71CA-11E1-9E33-C80AA9429562";;
              *"SHOW GLOBAL VARIABLES"*) printf 'log_bin\\tON\\nbinlog_format\\tROW\\nbinlog_row_image\\tFULL\\nunrelated\\tx\\n'; if [ -f "{$dir}/untrusted" ]; then printf 'log_bin_trust_function_creators\\tOFF\\n'; fi;;
              *"FLUSH BINARY LOGS"*) touch "{$dir}/flushed";;
              *"SHOW BINARY LOGS"*) cat "{$dir}/logs";;
              *"SHOW BINARY LOG STATUS"*|*"SHOW MASTER STATUS"*) printf 'binlog.000004\\t198\\t\\t\\tuuid-a:1-13,\\\\nuuid-b:1-2\\n';;
              *"SHOW GRANTS"*) if [ -f "{$dir}/grants" ]; then cat "{$dir}/grants"; else printf 'GRANT RELOAD, REPLICATION SLAVE ON *.* TO `bk`@`%%`\\n'; fi;;
              *"--binary-mode"*) cat > "{$dir}/replayed.sql"; echo replay >> "{$dir}/order";;
              *"--commands=OFF"*) cat > "{$dir}/imported.sql"; echo import >> "{$dir}/order";;
            esac
            exit 0
            SH);
    }

    /**
     * A `mysqlbinlog`: `--version` like Oracle 9.7.2; `--raw` copies `$dir/server/<name>` into the result prefix
     * (or fails with the server error in `$dir/raw-error`); a decode writes `$dir/decoded` (or a header per input file).
     * Every argv goes to `$dir/binlog-args`.
     */
    public static function mysqlbinlog(string $dir, string $version = 'mysqlbinlog  Ver 9.7.2 for Linux on x86_64 (MySQL Community Server - GPL)'): string
    {
        return EngineFixtures::script($dir, 'fake-mysqlbinlog', <<<SH
            if [ "\$1" = "--version" ]; then echo "{$version}"; exit 0; fi
            echo "\$*" >> "{$dir}/binlog-args"
            echo "TZ=\$TZ" >> "{$dir}/binlog-env"
            out=""; raw=0; files=""
            for a in "\$@"; do
              case "\$a" in
                --result-file=*) out="\${a#--result-file=}";;
                --raw) raw=1;;
                --*) ;;
                *) files="\$files \$a";;
              esac
            done
            if [ \$raw -eq 1 ]; then
              if [ -f "{$dir}/raw-error" ]; then cat "{$dir}/raw-error" >&2; exit 1; fi
              for f in \$files; do
                if [ -f "{$dir}/short" ]; then head -c 3 "{$dir}/server/\$f" > "\$out\$f"; else cp "{$dir}/server/\$f" "\$out\$f"; fi
              done
              exit 0
            fi
            if [ -f "{$dir}/decode-error" ]; then echo "mysqlbinlog: decode failed" >&2; exit 2; fi
            n=0; for f in \$files; do n=\$((n+1)); done
            if [ -f "{$dir}/decoded.\$n" ]; then cp "{$dir}/decoded.\$n" "\$out"; elif [ -f "{$dir}/decoded" ]; then cp "{$dir}/decoded" "\$out"; else echo "-- decoded \$files" > "\$out"; fi
            exit 0
            SH);
    }

    /**
     * @param list<array{name: string, size: int}> $logs
     * @param list<array{name: string, size: int}>|null $afterFlush
     */
    public static function server(array $logs, string $uuid = ChainFixtures::UUID, string $version = '9.7.2', ?array $afterFlush = null, string $format = 'ROW'): FakeBinlogServer
    {
        return new FakeBinlogServer($logs, $uuid, $version, $afterFlush, $format);
    }
}
