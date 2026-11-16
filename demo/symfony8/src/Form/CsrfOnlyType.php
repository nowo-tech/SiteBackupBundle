<?php

declare(strict_types=1);

namespace App\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormKitAbstractType;
use Override;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * CSRF-only POST of the demo force-setup flag on/off (REQ-DEMO-015, FormKit profile `demo`). No fields: the template renders the CSRF child through the
 * REQ-TWIG-003 child loop and keeps its own submit button outside the loop (REQ-TWIG-005).
 *
 * @extends FormKitAbstractType<null>
 */
#[FormKitConfig('demo')]
final class CsrfOnlyType extends FormKitAbstractType
{
    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => true,
            'csrf_token_id'   => 'demo',
            'csrf_field_name' => '_csrf_token',
        ]);
        $resolver->setAllowedTypes('csrf_token_id', 'string');
    }
}
