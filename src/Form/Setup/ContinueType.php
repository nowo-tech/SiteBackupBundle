<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Form\Setup;

use Nowo\SiteBackupBundle\Form\AbstractSiteBackupFormType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;

final class ContinueType extends AbstractSiteBackupFormType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('action', HiddenType::class, [
            'data' => 'continue',
        ]);
    }

    protected function csrfTokenId(): string
    {
        return 'nowo_site_backup_setup';
    }
}
