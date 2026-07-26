<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Setup\Step;

use Nowo\SiteBackupBundle\Setup\AdminUserProvisionerInterface;
use Nowo\SiteBackupBundle\Setup\SetupContext;
use Nowo\SiteBackupBundle\Setup\SetupStepInput;
use Nowo\SiteBackupBundle\Setup\SetupStepResult;
use Throwable;

use function is_string;

final class AdminUserStep extends AbstractSetupStep
{
    /**
     * @param list<string> $roles
     */
    public function __construct(
        string $id,
        string $label,
        private readonly AdminUserProvisionerInterface $provisioner,
        private readonly array $roles = ['ROLE_SUPER_ADMIN'],
        private readonly bool $skipIfAdminExists = true,
    ) {
        parent::__construct($id, $label, 'form');
    }

    public function isComplete(SetupContext $ctx): bool
    {
        if (parent::isComplete($ctx)) {
            return true;
        }

        return $this->skipIfAdminExists && $this->provisioner->adminExists();
    }

    public function run(SetupContext $ctx, SetupStepInput $input): SetupStepResult
    {
        if ($this->skipIfAdminExists && $this->provisioner->adminExists()) {
            return SetupStepResult::ok('Admin user already exists — skipped.');
        }

        $emailAnswer = $ctx->getAnswer('admin_email');
        $passAnswer  = $ctx->getAnswer('admin_password');
        $email       = $input->getString('email', is_string($emailAnswer) ? $emailAnswer : '');
        $password    = $input->getString('password', is_string($passAnswer) ? $passAnswer : '');

        if ($email === '' || $password === '') {
            return SetupStepResult::waitingForInput('Provide admin email and password.');
        }

        try {
            $this->provisioner->createAdmin([
                'email'    => $email,
                'password' => $password,
                'roles'    => $this->roles,
            ]);
            $ctx->setAnswer('admin_email', $email);

            return SetupStepResult::ok('Admin user created.');
        } catch (Throwable $e) {
            return SetupStepResult::fail($e->getMessage());
        }
    }
}
