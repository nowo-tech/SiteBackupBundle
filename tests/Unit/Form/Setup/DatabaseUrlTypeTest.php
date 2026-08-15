<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Form\Setup;

use Nowo\SiteBackupBundle\Form\Setup\DatabaseUrlType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\Forms;

final class DatabaseUrlTypeTest extends TestCase
{
    private FormFactoryInterface $factory;

    protected function setUp(): void
    {
        $this->factory = Forms::createFormFactoryBuilder()->getFormFactory();
    }

    public function testOptionalStepKeepsFieldOptionalWhenConnectionFailed(): void
    {
        $form = $this->factory->create(DatabaseUrlType::class, null, [
            'csrf_protection'      => false,
            'db_connection_failed' => true,
            'optional'             => true,
        ]);

        self::assertFalse($form->get('database_url')->getConfig()->getOption('required'));
    }

    public function testRequiredStepMarksFieldRequiredWhenConnectionFailed(): void
    {
        $form = $this->factory->create(DatabaseUrlType::class, null, [
            'csrf_protection'      => false,
            'db_connection_failed' => true,
            'optional'             => false,
        ]);

        self::assertTrue($form->get('database_url')->getConfig()->getOption('required'));
    }
}
