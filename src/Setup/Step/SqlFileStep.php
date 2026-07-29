<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Setup\Step;

use Nowo\SiteBackupBundle\Setup\SetupContext;
use Nowo\SiteBackupBundle\Setup\SetupStepInput;
use Nowo\SiteBackupBundle\Setup\SetupStepResult;
use Symfony\Component\Finder\Finder;
use Throwable;

use function array_unique;
use function array_values;
use function count;
use function file_get_contents;
use function glob;
use function is_dir;
use function is_file;
use function is_object;
use function ltrim;
use function rtrim;
use function sprintf;
use function str_contains;
use function trim;

/**
 * Executes SQL files. Prefer idempotent SQL in apps.
 * When if_exists is true and no files match, the step succeeds as a no-op.
 */
final class SqlFileStep extends AbstractSetupStep
{
    /**
     * @param list<string> $paths Absolute or project-relative paths / globs
     */
    public function __construct(
        string $id,
        string $label,
        private readonly array $paths,
        private readonly bool $ifExists = false,
        private readonly mixed $connection = null,
    ) {
        parent::__construct($id, $label);
    }

    public function isEnabled(SetupContext $ctx): bool
    {
        if (!$this->ifExists) {
            return true;
        }

        return $this->resolveFiles($ctx) !== [];
    }

    public function run(SetupContext $ctx, SetupStepInput $input): SetupStepResult
    {
        $files = $this->resolveFiles($ctx);
        if ($files === []) {
            if ($this->ifExists) {
                return SetupStepResult::ok('No SQL files to import (skipped).');
            }

            return SetupStepResult::fail('No SQL files matched the configured paths.');
        }

        if ($this->connection === null || !is_object($this->connection) || !method_exists($this->connection, 'executeStatement')) {
            return SetupStepResult::fail(
                'SQL import requires a DBAL connection with executeStatement(). Wire doctrine.dbal.default_connection or use a console dump-import command instead.',
            );
        }

        $log = [];
        try {
            foreach ($files as $file) {
                $sql = file_get_contents($file);
                if ($sql === false || trim($sql) === '') {
                    $log[] = sprintf('skip empty: %s', $file);
                    continue;
                }
                $this->connection->executeStatement($sql);
                $log[] = sprintf('imported: %s', $file);
            }

            return SetupStepResult::ok(sprintf('Imported %d SQL file(s).', count($files)), $log);
        } catch (Throwable $e) {
            return SetupStepResult::fail($e->getMessage(), $log);
        }
    }

    /**
     * @return list<string>
     */
    private function resolveFiles(SetupContext $ctx): array
    {
        $resolved = [];
        foreach ($this->paths as $path) {
            $path = $this->absolute($ctx, $path);
            if (str_contains($path, '*') || str_contains($path, '?')) {
                $matches = glob($path) ?: [];
                foreach ($matches as $match) {
                    if (is_file($match)) {
                        $resolved[] = $match;
                    }
                }
                continue;
            }
            if (is_file($path)) {
                $resolved[] = $path;
            } elseif (is_dir($path)) {
                $finder = (new Finder())->files()->in($path)->name('*.sql')->sortByName();
                foreach ($finder as $file) {
                    $resolved[] = $file->getPathname();
                }
            }
        }

        return array_values(array_unique($resolved));
    }

    private function absolute(SetupContext $ctx, string $path): string
    {
        if ($path !== '' && $path[0] === '/') {
            return $path;
        }

        return rtrim($ctx->getProjectDir(), '/\\') . '/' . ltrim($path, '/');
    }
}
