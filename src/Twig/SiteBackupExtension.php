<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Twig;

use Nowo\SiteBackupBundle\Model\RestoreProgress;
use Nowo\SiteBackupBundle\Service\SiteBackupManager;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class SiteBackupExtension extends AbstractExtension
{
    public function __construct(private readonly SiteBackupManager $manager)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('nowo_site_backup_is_restoring', $this->isRestoring(...)),
            new TwigFunction('nowo_site_backup_progress', $this->progress(...)),
        ];
    }

    public function isRestoring(): bool
    {
        return $this->manager->isRestoreActive();
    }

    public function progress(): RestoreProgress
    {
        return $this->manager->getRestoreProgress();
    }
}
