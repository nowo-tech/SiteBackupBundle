<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Form\Panel;

use Nowo\SiteBackupBundle\Form\AbstractSiteBackupFormType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;

final class PanelLoginType extends AbstractSiteBackupFormType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('action', HiddenType::class, [
                'data' => 'login',
            ])
            ->add('password', PasswordType::class, [
                'always_empty' => true,
                'attr'         => [
                    'autofocus'    => true,
                    'autocomplete' => 'current-password',
                ],
            ]);
    }

    protected function csrfTokenId(): string
    {
        return 'nowo_site_backup_login';
    }
}
