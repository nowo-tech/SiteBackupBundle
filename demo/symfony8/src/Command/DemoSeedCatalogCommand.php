<?php

declare(strict_types=1);

namespace App\Command;

use App\Setup\DemoSeedTabChecker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

use function dirname;
use function file_put_contents;
use function is_dir;
use function mkdir;

#[AsCommand(
    name: 'app:demo:seed-catalog',
    description: 'Demo runner for custom setup tab (writes var/site-backup/demo-seed.ok)',
)]
final class DemoSeedCatalogCommand extends Command
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = $this->projectDir . '/' . DemoSeedTabChecker::SEED_RELATIVE;
        $dir  = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            $output->writeln('<error>Cannot create ' . $dir . '</error>');

            return Command::FAILURE;
        }

        file_put_contents($path, "seeded-at=" . gmdate('c') . "\n");
        $output->writeln('<info>Wrote ' . DemoSeedTabChecker::SEED_RELATIVE . '</info>');

        return Command::SUCCESS;
    }
}
