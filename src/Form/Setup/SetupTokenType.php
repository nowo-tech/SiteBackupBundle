<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Form\Setup;

use Nowo\SiteBackupBundle\Form\AbstractSiteBackupFormType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Setup token gate (v1.18+): GET form that re-opens the wizard with ?token=… (no CSRF: it only carries the secret itself).
 */
final class SetupTokenType extends AbstractSiteBackupFormType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('token', TextType::class, [
            'label'    => 'setup.token.field_label',
            'required' => true,
            'attr'     => [
                'placeholder'  => 'setup.token.field_placeholder',
                'autocomplete' => 'off',
                'spellcheck'   => 'false',
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        parent::configureOptions($resolver);
        $resolver->setDefaults([
            'csrf_protection' => false,
            'method'          => 'GET',
        ]);
    }

    protected function csrfTokenId(): string
    {
        return 'nowo_site_backup_setup_token';
    }
}
