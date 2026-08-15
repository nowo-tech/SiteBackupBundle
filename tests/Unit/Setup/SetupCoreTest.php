<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Tests\Unit\Setup;

use Nowo\SiteBackupBundle\Setup\NullAdminUserProvisioner;
use Nowo\SiteBackupBundle\Setup\NullDurableSetupDoneStore;
use Nowo\SiteBackupBundle\Setup\SetupContext;
use Nowo\SiteBackupBundle\Setup\SetupStepInput;
use Nowo\SiteBackupBundle\Setup\SetupStepResult;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SetupCoreTest extends TestCase
{
    public function testSetupContext(): void
    {
        $ctx = new SetupContext('/tmp/project', 'fresh_install', ['sample_data' => true]);
        self::assertSame('/tmp/project', $ctx->getProjectDir());
        self::assertTrue($ctx->wantsSampleData());

        $ctx->setAnswer('email', 'a@b.c');
        self::assertSame('a@b.c', $ctx->getAnswer('email'));
        self::assertNull($ctx->getAnswer('missing'));

        $ctx->markCompleted('step1');
        $ctx->markCompleted('step1');
        self::assertTrue($ctx->isCompleted('step1'));
        self::assertSame(['step1'], $ctx->getCompletedStepIds());

        $ctx->setOption('k', 'v');
        self::assertSame('v', $ctx->getOption('k'));
        self::assertNull($ctx->getOption('missing'));
    }

    public function testSetupStepInput(): void
    {
        $input = new SetupStepInput([
            'email' => 'a@b.c',
            'skip'  => 'true',
            'bad'   => 123,
        ]);

        self::assertSame(['email' => 'a@b.c', 'skip' => 'true', 'bad' => 123], $input->all());
        self::assertSame('a@b.c', $input->getString('email'));
        self::assertSame('', $input->getString('missing'));
        self::assertSame('', $input->getString('bad'));
        self::assertTrue($input->getBool('skip'));
        self::assertFalse($input->getBool('missing', false));
    }

    public function testSetupStepResult(): void
    {
        $ok = SetupStepResult::ok('done', ['log']);
        self::assertTrue($ok->isSuccess());
        self::assertFalse($ok->isWaitingForInput());
        self::assertSame('done', $ok->getMessage());
        self::assertSame(['log'], $ok->getLog());

        $fail = SetupStepResult::fail('err');
        self::assertFalse($fail->isSuccess());

        $waiting = SetupStepResult::waitingForInput('need input');
        self::assertFalse($waiting->isSuccess());
        self::assertTrue($waiting->isWaitingForInput());
    }

    public function testNullAdminUserProvisioner(): void
    {
        $provisioner = new NullAdminUserProvisioner();
        self::assertFalse($provisioner->adminExists());
        $this->expectException(RuntimeException::class);
        $provisioner->createAdmin(['email' => 'admin@example.com', 'password' => 'secret']);
    }

    public function testNullDurableSetupDoneStore(): void
    {
        $store = new NullDurableSetupDoneStore();
        self::assertFalse($store->isDone());
        $store->markDone();
        self::assertFalse($store->isDone());
    }
}
