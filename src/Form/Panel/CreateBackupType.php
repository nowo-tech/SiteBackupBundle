<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Form\Panel;

use Nowo\SiteBackupBundle\Form\AbstractSiteBackupFormType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;

final class CreateBackupType extends AbstractSiteBackupFormType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('action', HiddenType::class, [
                'data' => 'create',
            ])
            ->add('label', TextType::class, [
                'required' => false,
            ]);
    }

    protected function csrfTokenId(): string
    {
        return 'nowo_site_backup_panel';
    }
}
