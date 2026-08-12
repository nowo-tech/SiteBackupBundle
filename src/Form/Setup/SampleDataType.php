<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Form\Setup;

use Nowo\SiteBackupBundle\Form\AbstractSiteBackupFormType;

final class SampleDataType extends AbstractSiteBackupFormType
{
    protected function csrfTokenId(): string
    {
        return 'nowo_site_backup_setup';
    }
}
