<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Controller;

use Symfony\Component\Form\Extension\Csrf\CsrfExtension;
use Symfony\Component\Form\Extension\HttpFoundation\HttpFoundationExtension;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

trait CreatesFormFactory
{
    private function createFormFactory(?CsrfTokenManagerInterface $csrfTokenManager = null): FormFactoryInterface
    {
        $builder = Forms::createFormFactoryBuilder()
            ->addExtension(new HttpFoundationExtension());

        if ($csrfTokenManager instanceof CsrfTokenManagerInterface) {
            $builder->addExtension(new CsrfExtension($csrfTokenManager));
        }

        return $builder->getFormFactory();
    }
}
