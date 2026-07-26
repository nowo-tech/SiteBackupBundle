<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle;

use Nowo\SiteBackupBundle\DependencyInjection\Compiler\TwigPathsPass;
use Nowo\SiteBackupBundle\DependencyInjection\SiteBackupExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class NowoSiteBackupBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new TwigPathsPass());
    }

    public function getContainerExtension(): ?ExtensionInterface
    {
        if ($this->extension === null) {
            $this->extension = new SiteBackupExtension();
        }

        return $this->extension instanceof ExtensionInterface ? $this->extension : null;
    }
}
