<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Setup;

use InvalidArgumentException;
use Nowo\SiteBackupBundle\Setup\Step\AdminUserStep;
use Nowo\SiteBackupBundle\Setup\Step\CacheClearStep;
use Nowo\SiteBackupBundle\Setup\Step\ConsoleStep;
use Nowo\SiteBackupBundle\Setup\Step\DatabaseCreateStep;
use Nowo\SiteBackupBundle\Setup\Step\DatabaseUrlStep;
use Nowo\SiteBackupBundle\Setup\Step\MarkerStep;
use Nowo\SiteBackupBundle\Setup\Step\MigrationsStep;
use Nowo\SiteBackupBundle\Setup\Step\RequirementsStep;
use Nowo\SiteBackupBundle\Setup\Step\SampleDataStep;
use Nowo\SiteBackupBundle\Setup\Step\SchemaUpdateStep;
use Nowo\SiteBackupBundle\Setup\Step\SqlFileStep;
use Nowo\SiteBackupBundle\Setup\Storage\SetupMarkerManager;

use function is_array;
use function is_string;
use function sprintf;

/**
 * Builds setup steps from declarative profile config.
 */
final class SetupStepFactory
{
    /**
     * @param array<string, SetupStepInterface> $customSteps keyed by type id
     */
    public function __construct(
        private readonly ConsoleProcessRunner $runner,
        private readonly SetupMarkerManager $markers,
        private readonly AdminUserProvisionerInterface $adminProvisioner,
        private readonly mixed $dbalConnection = null,
        private readonly array $customSteps = [],
    ) {
    }

    /**
     * @param array<string, mixed> $config
     */
    public function create(array $config, int $index): SetupStepInterface
    {
        $type = is_string($config['type'] ?? null) ? $config['type'] : '';
        if ($type === '') {
            throw new InvalidArgumentException(sprintf('Setup step #%d is missing type.', $index));
        }

        if (isset($this->customSteps[$type])) {
            return $this->customSteps[$type];
        }

        $id    = is_string($config['id'] ?? null) ? $config['id'] : sprintf('%s_%d', $type, $index);
        $label = is_string($config['label'] ?? null) ? $config['label'] : $this->defaultLabel($type);

        return match ($type) {
            'requirements' => new RequirementsStep(
                $id,
                $label,
                is_array($config['extensions'] ?? null) ? array_values(array_filter($config['extensions'], 'is_string')) : ['json', 'pdo'],
                is_array($config['writable'] ?? null) ? array_values(array_filter($config['writable'], 'is_string')) : ['var'],
                (bool) ($config['require_tar'] ?? true),
            ),
            'database_url'    => new DatabaseUrlStep($id, $label, (bool) ($config['optional'] ?? true)),
            'database_create' => new DatabaseCreateStep($id, $label, $this->runner),
            'cache_clear'     => new CacheClearStep($id, $label, $this->runner),
            'schema_update'   => new SchemaUpdateStep($id, $label, $this->runner),
            'migrations'      => new MigrationsStep($id, $label, $this->runner),
            'sql_file'        => new SqlFileStep(
                $id,
                $label,
                is_array($config['paths'] ?? null) ? array_values(array_filter($config['paths'], 'is_string')) : [],
                (bool) ($config['if_exists'] ?? false),
                $this->dbalConnection,
            ),
            'console' => new ConsoleStep(
                $id,
                $label,
                $this->runner,
                $this->parseCommand($config['command'] ?? null),
            ),
            'admin_user' => new AdminUserStep(
                $id,
                $label,
                $this->adminProvisioner,
                is_array($config['roles'] ?? null) ? array_values(array_filter($config['roles'], 'is_string')) : ['ROLE_SUPER_ADMIN'],
                (bool) ($config['skip_if_admin_exists'] ?? true),
            ),
            'sample_data' => new SampleDataStep(
                $id,
                $label,
                $this->runner,
                $this->parseCommandsList($config['commands'] ?? []),
                is_string($config['when'] ?? null) ? $config['when'] : 'opt_in',
            ),
            'marker' => new MarkerStep($id, $label, $this->markers, (bool) ($config['write_done'] ?? true)),
            default  => throw new InvalidArgumentException(sprintf('Unknown setup step type "%s".', $type)),
        };
    }

    /**
     * @param list<array<string, mixed>> $stepConfigs
     *
     * @return list<SetupStepInterface>
     */
    public function createAll(array $stepConfigs): array
    {
        $steps = [];
        foreach ($stepConfigs as $i => $config) {
            $steps[] = $this->create($config, $i);
        }

        return $steps;
    }

    private function defaultLabel(string $type): string
    {
        return match ($type) {
            'requirements'    => 'Check requirements',
            'database_url'    => 'Database URL',
            'database_create' => 'Create database',
            'cache_clear'     => 'Clear cache',
            'schema_update'   => 'Update schema',
            'migrations'      => 'Run migrations',
            'sql_file'        => 'Import SQL',
            'console'         => 'Run command',
            'admin_user'      => 'Create admin user',
            'sample_data'     => 'Sample data',
            'marker'          => 'Finalize',
            default           => $type,
        };
    }

    /**
     * @return list<string>
     */
    private function parseCommand(mixed $command): array
    {
        if (is_array($command)) {
            return array_values(array_filter($command, 'is_string'));
        }
        if (!is_string($command) || $command === '') {
            throw new InvalidArgumentException('console step requires a command string or argument list.');
        }

        return preg_split('/\s+/', trim($command)) ?: [];
    }

    /**
     * @return list<list<string>>
     */
    private function parseCommandsList(mixed $commands): array
    {
        if (!is_array($commands)) {
            return [];
        }
        $out = [];
        foreach ($commands as $cmd) {
            $out[] = $this->parseCommand($cmd);
        }

        return $out;
    }
}
