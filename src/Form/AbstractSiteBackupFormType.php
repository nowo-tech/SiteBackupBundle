<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormTypeInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Base of the panel and setup wizard forms: a FormKit Type (REQ-TWIG-005 rule 11) on the `site_backup` profile
 * (`nowo_form_kit.profiles.site_backup`, prepended by the bundle extension; hosts override it there).
 *
 * @extends AbstractType<array<string, mixed>>
 */
#[FormKitConfig(self::FORM_KIT_PROFILE)]
abstract class AbstractSiteBackupFormType extends AbstractType
{
    use FormOptionsTrait;

    /** FormKit profile of the bundle forms (key in `nowo_form_kit.profiles`). */
    public const FORM_KIT_PROFILE = 'site_backup';

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

    /**
     * Adds a field with the options of the `site_backup` FormKit profile merged in. Without FormKit's option merger
     * (FormKitBundle not registered in the kernel, plain form factories in tests) the explicit options are used as is.
     *
     * @param FormBuilderInterface<mixed> $builder
     * @param class-string<FormTypeInterface<mixed>> $type
     * @param array<string, mixed> $options
     */
    protected function addField(FormBuilderInterface $builder, string $name, string $type, array $options = []): void
    {
        if (!isset($this->formOptionsMerger)) {
            $builder->add($name, $type, $options);

            return;
        }

        $this->addWithDefaults($builder, $name, $type, $options);
    }
}
