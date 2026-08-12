<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Form\Setup;

use Nowo\SiteBackupBundle\Form\AbstractSiteBackupFormType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;

final class AdminUserType extends AbstractSiteBackupFormType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'attr' => [
                    'autocomplete' => 'username',
                ],
            ])
            ->add('password', PasswordType::class, [
                'always_empty' => true,
                'attr'         => [
                    'autocomplete' => 'new-password',
                ],
            ]);
    }

    protected function csrfTokenId(): string
    {
        return 'nowo_site_backup_setup';
    }
}
