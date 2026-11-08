<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Setup;

use Nowo\SiteBackupBundle\Setup\Step\AdminUserStep;
use Nowo\SiteBackupBundle\Setup\Step\BootstrapModeStep;
use Nowo\SiteBackupBundle\Setup\Step\ConditionalAnswerStep;
use Nowo\SiteBackupBundle\Setup\Step\DatabaseUrlStep;
use Nowo\SiteBackupBundle\Setup\Step\SqlFileStep;
use Nowo\SiteBackupBundle\Setup\Step\TabStep;

use function array_filter;
use function array_values;

/**
 * Steps refused on a re-opened wizard (1.32+, owner decision IN-I1): admin creation ({@see AdminUserStep}), the
 * `.env.local` rewrite ({@see DatabaseUrlStep}), the SQL import ({@see SqlFileStep} and its chooser
 * {@see BootstrapModeStep}) and any host step implementing {@see InitialInstallOnlyStepInterface}.
 */
final class InitialInstallOnlySteps
{
    public static function isInitialInstallOnly(SetupStepInterface $step): bool
    {
        while (true) {
            if ($step instanceof InitialInstallOnlyStepInterface
                || $step instanceof AdminUserStep
                || $step instanceof DatabaseUrlStep
                || $step instanceof SqlFileStep
                || $step instanceof BootstrapModeStep
            ) {
                return true;
            }

            if (!$step instanceof TabStep && !$step instanceof ConditionalAnswerStep) {
                return false;
            }

            $step = $step->getInner();
        }
    }

    /**
     * @param list<SetupStepInterface> $steps
     *
     * @return list<SetupStepInterface>
     */
    public static function withoutInitialInstallOnly(array $steps): array
    {
        return array_values(array_filter($steps, static fn (SetupStepInterface $step): bool => !self::isInitialInstallOnly($step)));
    }
}
