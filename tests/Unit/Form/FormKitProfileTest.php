<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\DependencyInjection\Configuration as FormKitConfiguration;
use Nowo\FormKitBundle\DependencyInjection\FormKitExtension;
use Nowo\FormKitBundle\Form\Constraint\ConstraintDefinitionFactory;
use Nowo\FormKitBundle\Form\FormOptionsMerger;
use Nowo\SiteBackupBundle\DependencyInjection\SiteBackupExtension;
use Nowo\SiteBackupBundle\Form\AbstractSiteBackupFormType;
use Nowo\SiteBackupBundle\Form\Panel\CreateBackupType;
use Nowo\SiteBackupBundle\Form\Panel\PanelActionType;
use Nowo\SiteBackupBundle\Form\Panel\PanelLoginType;
use Nowo\SiteBackupBundle\Form\Setup\AdminUserType;
use Nowo\SiteBackupBundle\Form\Setup\BootstrapModeType;
use Nowo\SiteBackupBundle\Form\Setup\ContinueType;
use Nowo\SiteBackupBundle\Form\Setup\DatabaseUrlType;
use Nowo\SiteBackupBundle\Form\Setup\ResetType;
use Nowo\SiteBackupBundle\Form\Setup\SampleDataType;
use Nowo\SiteBackupBundle\Form\Setup\SetupTokenType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\Form\FormView;

/**
 * REQ-TWIG-005 rule 11: the bundle forms are FormKit Types on the `site_backup` profile, and the stock profile renders
 * them exactly as their explicit options.
 */
final class FormKitProfileTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string<AbstractSiteBackupFormType>, array<string, mixed>}>
     */
    public static function formTypes(): iterable
    {
        yield 'create backup' => [CreateBackupType::class, []];
        yield 'panel action' => [PanelActionType::class, ['panel_action' => 'restore', 'backup_id' => 'b1']];
        yield 'panel login' => [PanelLoginType::class, []];
        yield 'admin user' => [AdminUserType::class, []];
        yield 'bootstrap mode' => [BootstrapModeType::class, []];
        yield 'continue' => [ContinueType::class, []];
        yield 'database url' => [DatabaseUrlType::class, ['db_connection_failed' => true, 'optional' => false]];
        yield 'reset' => [ResetType::class, []];
        yield 'sample data' => [SampleDataType::class, []];
        yield 'setup token' => [SetupTokenType::class, []];
    }

    /**
     * @param class-string<AbstractSiteBackupFormType> $class
     * @param array<string, mixed> $options
     */
    #[DataProvider('formTypes')]
    public function testFormTypeUsesTheSiteBackupProfileAndRendersTheSameFields(string $class, array $options): void
    {
        $type = new $class();
        self::assertSame(AbstractSiteBackupFormType::FORM_KIT_PROFILE, FormKitConfig::nameFrom($type));

        $plain = $this->view(Forms::createFormFactoryBuilder()->getFormFactory(), $class, $options);

        $type->setFormOptionsMerger($this->merger(SiteBackupExtension::formKitProfile()));
        $kit = $this->view(Forms::createFormFactoryBuilder()->addType($type)->getFormFactory(), $class, $options);

        self::assertSame(array_keys($plain->children), array_keys($kit->children));
        foreach ($plain->children as $name => $child) {
            foreach (['label', 'attr', 'row_attr', 'help', 'required', 'translation_domain', 'value'] as $var) {
                self::assertSame($child->vars[$var] ?? null, $kit->children[$name]->vars[$var] ?? null, $class . ' ' . $name . '.' . $var);
            }
        }
    }

    public function testHostProfileClassesReachTheFields(): void
    {
        $type    = new CreateBackupType();
        $profile = ['defaults' => ['attr' => ['class' => 'host-input'], 'row_attr' => [], 'help_attr' => []]] + SiteBackupExtension::formKitProfile();
        $type->setFormOptionsMerger($this->merger($profile));

        $view = $this->view(Forms::createFormFactoryBuilder()->addType($type)->getFormFactory(), CreateBackupType::class, []);

        self::assertSame('host-input', $view->children['label']->vars['attr']['class'] ?? null);
        self::assertSame('panel.index.label_placeholder', $view->children['label']->vars['attr']['placeholder'] ?? null);
    }

    public function testPrependSkipsHostsWithoutFormKit(): void
    {
        $container = new ContainerBuilder();
        (new SiteBackupExtension())->prepend($container);

        self::assertSame([], $container->getExtensionConfig('nowo_form_kit'));
    }

    public function testPrependSeedsTheProfileAndKeepsTheImplicitDefaultProfile(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new FormKitExtension());
        (new SiteBackupExtension())->prepend($container);

        $config = (new Processor())->processConfiguration(new FormKitConfiguration(), $container->getExtensionConfig('nowo_form_kit'));

        self::assertSame('default', $config['default_profile']);
        self::assertArrayHasKey('default', $config['profiles']);
        self::assertSame('NowoSiteBackupBundle', $config['profiles']['site_backup']['translation_domain']);
        self::assertFalse($config['profiles']['site_backup']['auto_help']);
        self::assertFalse($config['profiles']['site_backup']['auto_placeholder']);
    }

    public function testPrependAddsTheProfileNextToTheHostConfig(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new FormKitExtension());
        $container->loadFromExtension('nowo_form_kit', ['default_profile' => 'app', 'profiles' => ['app' => ['alias' => 'app']]]);
        (new SiteBackupExtension())->prepend($container);

        $config = (new Processor())->processConfiguration(new FormKitConfiguration(), $container->getExtensionConfig('nowo_form_kit'));

        self::assertSame('app', $config['default_profile']);
        self::assertArrayNotHasKey('default', $config['profiles']);
        self::assertArrayHasKey('site_backup', $config['profiles']);
    }

    public function testPrependKeepsAHostDefinedProfile(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new FormKitExtension());
        $container->loadFromExtension('nowo_form_kit', ['profiles' => ['site_backup' => ['alias' => 'site_backup', 'translation_domain' => 'admin']]]);
        (new SiteBackupExtension())->prepend($container);

        self::assertCount(1, $container->getExtensionConfig('nowo_form_kit'));
    }

    /**
     * @param array<string, mixed> $profile
     */
    private function merger(array $profile): FormOptionsMerger
    {
        /** @var array{translation_domain: string, defaults: array{attr: array<string, mixed>, row_attr: array<string, mixed>, help_attr: array<string, mixed>}, field_types: array<string, array<string, mixed>>} $config */
        $config = $profile + ['defaults' => ['attr' => [], 'row_attr' => [], 'help_attr' => []], 'field_types' => []];

        return new FormOptionsMerger([AbstractSiteBackupFormType::FORM_KIT_PROFILE => $config], AbstractSiteBackupFormType::FORM_KIT_PROFILE, new ConstraintDefinitionFactory());
    }

    /**
     * @param class-string<AbstractSiteBackupFormType> $class
     * @param array<string, mixed> $options
     */
    private function view(FormFactoryInterface $factory, string $class, array $options): FormView
    {
        return $factory->createNamed('', $class, null, ['csrf_protection' => false] + $options)->createView();
    }
}
