<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Form\Setup;

use Nowo\SiteBackupBundle\Form\AbstractSiteBackupFormType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class DatabaseUrlType extends AbstractSiteBackupFormType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('database_url', UrlType::class, [
            'attr' => [
                'autocomplete' => 'off',
                'placeholder'  => 'postgresql://user:pass@db:5432/app',
            ],
            'required' => $options['db_connection_failed'],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        parent::configureOptions($resolver);

        $resolver->setDefaults([
            'db_connection_failed' => false,
        ]);
        $resolver->setAllowedTypes('db_connection_failed', 'bool');
    }

    protected function csrfTokenId(): string
    {
        return 'nowo_site_backup_setup';
    }
}
