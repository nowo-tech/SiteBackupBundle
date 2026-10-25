<?php

declare(strict_types=1);

namespace App\Twig;

use App\Form\DemoForceSetupForms;
use Symfony\Component\Form\FormView;
use Twig\Attribute\AsTwigFunction;

/**
 * Exposes the demo force-setup "off" form to the guest layout, which is rendered by bundle controllers (setup
 * wizard) that cannot pass demo variables (REQ-TWIG-005: no raw form markup in Twig).
 */
final readonly class DemoFormExtension
{
    public function __construct(private DemoForceSetupForms $forms)
    {
    }

    #[AsTwigFunction('demo_force_setup_off_form')]
    public function forceSetupOffForm(): FormView
    {
        return $this->forms->create('off', ['class' => 'demo-gate-escape'])->createView();
    }
}
