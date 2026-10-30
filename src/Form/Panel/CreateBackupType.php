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
        $this->addField($builder, 'action', HiddenType::class, [
            'label' => null, // hidden: no FormKit convention label key
            'data'  => 'create',
        ]);
        $this->addField($builder, 'label', TextType::class, [
            'label'    => 'panel.index.label',
            'required' => false,
            'attr'     => [
                'placeholder' => 'panel.index.label_placeholder',
                'maxlength'   => 120,
            ],
        ]);
    }

    protected function csrfTokenId(): string
    {
        return 'nowo_site_backup_panel';
    }
}
