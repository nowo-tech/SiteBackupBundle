<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<array<string, mixed>>
 */
abstract class AbstractSiteBackupFormType extends AbstractType
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'allow_extra_fields' => true,
            'csrf_field_name'    => '_csrf_token',
            'csrf_protection'    => true,
            'csrf_token_id'      => $this->csrfTokenId(),
            'data_class'         => null,
            'method'             => 'POST',
            // v1.21+: field labels / help are bundle translation keys (UiKit scaffolds render the default rows).
            'translation_domain' => 'NowoSiteBackupBundle',
        ]);
    }

    public function getBlockPrefix(): string
    {
        return '';
    }

    abstract protected function csrfTokenId(): string;
}
