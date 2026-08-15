<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Form\Setup;

use Nowo\SiteBackupBundle\Form\AbstractSiteBackupFormType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class DatabaseUrlType extends AbstractSiteBackupFormType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // Optional steps must stay skippable even when Doctrine cannot SELECT 1 yet
        // (empty / missing schema). Cold-start: Skip keeps .env DATABASE_URL; next
        // step is typically database_create.
        $required = (bool) $options['db_connection_failed'] && !(bool) $options['optional'];

        $builder->add('database_url', UrlType::class, [
            'attr' => [
                'autocomplete' => 'off',
                'placeholder'  => 'postgresql://user:pass@db:5432/app',
            ],
            'required' => $required,
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        parent::configureOptions($resolver);

        $resolver->setDefaults([
            'db_connection_failed' => false,
            'optional'             => true,
        ]);
        $resolver->setAllowedTypes('db_connection_failed', 'bool');
        $resolver->setAllowedTypes('optional', 'bool');

        // Hosts may pass db_connection_failed from detectors; keep optional authoritative.
        $resolver->setNormalizer('db_connection_failed', static function (Options $options, bool $failed): bool {
            if ((bool) $options['optional']) {
                return $failed; // still show the warn banner; field stays optional via buildForm
            }

            return $failed;
        });
    }

    protected function csrfTokenId(): string
    {
        return 'nowo_site_backup_setup';
    }
}
