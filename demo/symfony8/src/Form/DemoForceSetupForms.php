<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Builds the demo force-setup flag forms (on / off) so the home page, the guest layout and the POST handlers share
 * one Form contract (REQ-TWIG-005): form name {@code force_setup_<state>}, CSRF token id {@code demo_force_setup}.
 */
final readonly class DemoForceSetupForms
{
    public const CSRF_TOKEN_ID = 'demo_force_setup';

    public function __construct(
        private FormFactoryInterface $formFactory,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @param 'on'|'off'           $state
     * @param array<string, mixed> $attr  extra HTML attributes for the form tag
     *
     * @return FormInterface<null>
     */
    public function create(string $state, array $attr = []): FormInterface
    {
        return $this->formFactory->createNamed('force_setup_' . $state, CsrfOnlyType::class, null, [
            'action'        => $this->urlGenerator->generate('demo_force_setup_' . $state),
            'method'        => 'POST',
            'csrf_token_id' => self::CSRF_TOKEN_ID,
            'attr'          => $attr,
        ]);
    }
}
