<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Form\Panel;

use Nowo\SiteBackupBundle\Form\AbstractSiteBackupFormType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

use function is_string;

final class PanelActionType extends AbstractSiteBackupFormType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('action', HiddenType::class, [
            'data' => $options['action'],
        ]);

        if (is_string($options['backup_id']) && $options['backup_id'] !== '') {
            $builder->add('backup_id', HiddenType::class, [
                'data' => $options['backup_id'],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        parent::configureOptions($resolver);

        $resolver->setDefaults([
            'action'    => '',
            'backup_id' => null,
        ]);
        $resolver->setAllowedTypes('action', 'string');
        $resolver->setAllowedTypes('backup_id', ['null', 'string']);
    }

    protected function csrfTokenId(): string
    {
        return 'nowo_site_backup_panel';
    }
}
