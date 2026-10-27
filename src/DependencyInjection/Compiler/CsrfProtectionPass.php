<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\DependencyInjection\Compiler;

use LogicException;
use Nowo\SiteBackupBundle\Controller\SetupWizardController;
use Nowo\SiteBackupBundle\Controller\SiteBackupPanelController;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Fails closed when the backup panel or the setup wizard is enabled without form CSRF protection (REQ-SEC-005).
 *
 * The mutations of the backup panel or the setup wizard rely on Symfony's form CSRF extension: forms that declare their own `csrf_protection` /
 * `csrf_token_id` options would otherwise validate without a token when `form.type_extension.csrf` is missing or
 * `framework.form.csrf_protection` is false. The container refuses to compile instead.
 *
 * Both controllers already deny mutations without a token manager; this pass also covers
 * `framework.form.csrf_protection: false`, where the token manager exists but forms skip validation.
 */
final class CsrfProtectionPass implements CompilerPassInterface
{
    /**
     * Throws when the backup panel or the setup wizard is enabled and form CSRF protection or the CSRF token manager is unavailable.
     */
    public function process(ContainerBuilder $container): void
    {
        // The extension removes each controller when its feature (web_ui / setup) is disabled.
        if (
            !$container->hasDefinition(SiteBackupPanelController::class)
            && !$container->hasDefinition(SetupWizardController::class)
        ) {
            return;
        }

        $formCsrfDisabled = $container->hasParameter('form.type_extension.csrf.enabled')
            && $container->getParameter('form.type_extension.csrf.enabled') === false;

        if (
            !$container->has('security.csrf.token_manager')
            || !$container->hasDefinition('form.type_extension.csrf')
            || $formCsrfDisabled
        ) {
            throw new LogicException('NowoSiteBackupBundle: the panel (web_ui.enabled) and the setup wizard (setup.enabled) require CSRF protection. Enable framework.csrf_protection and framework.form.csrf_protection (composer require symfony/security-csrf), or disable both.');
        }
    }
}
