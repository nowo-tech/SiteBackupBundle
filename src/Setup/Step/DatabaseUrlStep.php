<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Setup\Step;

use Nowo\SiteBackupBundle\Setup\SetupContext;
use Nowo\SiteBackupBundle\Setup\SetupStepInput;
use Nowo\SiteBackupBundle\Setup\SetupStepResult;
use Symfony\Component\Filesystem\Filesystem;

use function file_exists;
use function file_get_contents;
use function is_string;
use function preg_match;
use function preg_replace_callback;
use function rtrim;
use function sprintf;
use function str_contains;

/**
 * Optional DATABASE_URL capture into .env.local (never commits secrets to git).
 */
final class DatabaseUrlStep extends AbstractSetupStep
{
    public function __construct(
        string $id,
        string $label,
        private readonly bool $optional = true,
        private readonly Filesystem $filesystem = new Filesystem(),
    ) {
        parent::__construct($id, $label, 'form');
    }

    public function isOptional(): bool
    {
        return $this->optional;
    }

    public function run(SetupContext $ctx, SetupStepInput $input): SetupStepResult
    {
        $url = $input->getString('database_url', is_string($ctx->getAnswer('database_url')) ? $ctx->getAnswer('database_url') : '');

        if ($url === '') {
            if ($this->optional || $input->getBool('skip') || $input->getString('action') === 'skip') {
                return SetupStepResult::ok('Database URL step skipped (using existing env).');
            }

            return SetupStepResult::waitingForInput('Provide DATABASE_URL or skip.');
        }

        if (!preg_match('#^[a-z0-9+.-]+://#i', $url)) {
            return SetupStepResult::fail('DATABASE_URL looks invalid.');
        }

        // Single quotes keep Dotenv from expanding $VAR / $(cmd); a quote or line break would escape them.
        if (str_contains($url, "'") || preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            return SetupStepResult::fail('DATABASE_URL must not contain single quotes, line breaks or control characters (percent-encode them, e.g. %27).');
        }

        $envLocal   = rtrim($ctx->getProjectDir(), '/\\') . '/.env.local';
        $assignment = 'DATABASE_URL=' . $this->quoteEnv($url);
        $line       = $assignment . "\n";

        if (file_exists($envLocal)) {
            $existing = (string) file_get_contents($envLocal);
            if (str_contains($existing, 'DATABASE_URL=')) {
                // Callback (not a replacement string) so `$1` / `\1` in passwords stay literal.
                $existing = preg_replace_callback('/^DATABASE_URL=.*$/m', static fn (): string => $assignment, $existing) ?? $existing;
                $this->filesystem->dumpFile($envLocal, $existing);
            } else {
                $this->filesystem->appendToFile($envLocal, $line);
            }
        } else {
            $this->filesystem->dumpFile($envLocal, $line);
        }

        $ctx->setAnswer('database_url_set', true);

        return SetupStepResult::ok(sprintf('Wrote DATABASE_URL to %s', $envLocal));
    }

    /**
     * Always single-quoted: Symfony Dotenv reads single-quoted values literally (no `$` / `\` processing).
     */
    private function quoteEnv(string $value): string
    {
        return "'" . $value . "'";
    }
}
