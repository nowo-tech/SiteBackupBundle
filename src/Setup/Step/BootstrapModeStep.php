<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Setup\Step;

use Nowo\SiteBackupBundle\Setup\SetupContext;
use Nowo\SiteBackupBundle\Setup\SetupStepInput;
use Nowo\SiteBackupBundle\Setup\SetupStepResult;

use function is_string;

/**
 * First-class choice: guided install (create admin) vs full SQL database import.
 *
 * Answers set: bootstrap_mode = guided|full_database, optional sql_import_path
 * (a `.sql` file under var/site-backup/, see {@see SqlImportPath}).
 */
final class BootstrapModeStep extends AbstractSetupStep
{
    public const MODE_GUIDED = 'guided';

    public const MODE_FULL_DATABASE = 'full_database';

    /**
     * @param list<string> $defaultSqlPaths Project-relative candidates when mode is full_database
     */
    public function __construct(
        string $id,
        string $label,
        private readonly array $defaultSqlPaths = [
            'var/site-backup/full-import.sql',
            'var/site-backup/last-restore-dump.sql',
        ],
    ) {
        parent::__construct($id, $label, 'form');
    }

    public function run(SetupContext $ctx, SetupStepInput $input): SetupStepResult
    {
        $existing = $ctx->getAnswer('bootstrap_mode');
        if (is_string($existing) && ($existing === self::MODE_GUIDED || $existing === self::MODE_FULL_DATABASE)) {
            return SetupStepResult::ok('Bootstrap mode: ' . $existing);
        }

        $mode = $input->getString('bootstrap_mode', $input->getString('action'));
        if ($mode !== self::MODE_GUIDED && $mode !== self::MODE_FULL_DATABASE) {
            return SetupStepResult::waitingForInput('Choose guided setup or load a full database dump.');
        }

        if ($mode === self::MODE_GUIDED) {
            $ctx->setAnswer('bootstrap_mode', self::MODE_GUIDED);

            return SetupStepResult::ok('Guided setup selected (admin form + migrations + loaders).');
        }

        $path = $input->getString('sql_import_path');
        if ($path === '') {
            $path = $this->firstExistingPath($ctx);
        }

        if ($path === '' || SqlImportPath::resolve($ctx->getProjectDir(), $path) === null) {
            return SetupStepResult::waitingForInput(
                'Provide the path of a full .sql dump inside var/site-backup/ (e.g. var/site-backup/full-import.sql).',
            );
        }

        $ctx->setAnswer('bootstrap_mode', self::MODE_FULL_DATABASE);
        $ctx->setAnswer('sql_import_path', $path);

        return SetupStepResult::ok('Full database import selected (' . $path . '). Admin form will be skipped if users already exist.');
    }

    private function firstExistingPath(SetupContext $ctx): string
    {
        foreach ($this->defaultSqlPaths as $candidate) {
            if (SqlImportPath::resolve($ctx->getProjectDir(), $candidate) !== null) {
                return $candidate;
            }
        }

        return '';
    }
}
