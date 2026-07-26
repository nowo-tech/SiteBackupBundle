<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Setup\Step;

use Nowo\SiteBackupBundle\Setup\SetupContext;
use Nowo\SiteBackupBundle\Setup\SetupStepInput;
use Nowo\SiteBackupBundle\Setup\SetupStepResult;

use function extension_loaded;
use function function_exists;
use function implode;
use function is_dir;
use function is_writable;
use function ltrim;
use function mkdir;
use function rtrim;
use function sprintf;

final class RequirementsStep extends AbstractSetupStep
{
    /**
     * @param list<string> $requiredExtensions
     * @param list<string> $writableRelativeDirs
     */
    public function __construct(
        string $id,
        string $label,
        private readonly array $requiredExtensions = ['json', 'pdo'],
        private readonly array $writableRelativeDirs = ['var'],
        private readonly bool $requireTar = true,
    ) {
        parent::__construct($id, $label, 'confirm');
    }

    public function run(SetupContext $ctx, SetupStepInput $input): SetupStepResult
    {
        $log    = [];
        $failed = [];

        foreach ($this->requiredExtensions as $ext) {
            if (extension_loaded($ext)) {
                $log[] = sprintf('ext-%s: ok', $ext);
            } else {
                $failed[] = sprintf('Missing PHP extension: %s', $ext);
            }
        }

        if ($this->requireTar) {
            if (function_exists('exec')) {
                $out = [];
                @exec('tar --version 2>&1', $out, $code);
                if ($code === 0) {
                    $log[] = 'tar: ok';
                } else {
                    $failed[] = 'tar binary not available';
                }
            } else {
                $failed[] = 'exec() disabled; cannot verify tar';
            }
        }

        foreach ($this->writableRelativeDirs as $rel) {
            $path = rtrim($ctx->getProjectDir(), '/\\') . '/' . ltrim($rel, '/');
            if (!is_dir($path)) {
                @mkdir($path, 0775, true);
            }
            if (is_dir($path) && is_writable($path)) {
                $log[] = sprintf('%s: writable', $rel);
            } else {
                $failed[] = sprintf('Directory not writable: %s', $rel);
            }
        }

        if ($failed !== []) {
            return SetupStepResult::fail(implode('; ', $failed), $log);
        }

        return SetupStepResult::ok('Requirements satisfied.', $log);
    }
}
