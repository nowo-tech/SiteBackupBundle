<?php

declare(strict_types=1);

namespace App\Setup;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\SiteBackupBundle\Setup\AdminUserProvisionerInterface;

final class AdminUserProvisioner implements AdminUserProvisionerInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function adminExists(): bool
    {
        $count = (int) $this->em->createQueryBuilder()
            ->select('COUNT(u.id)')
            ->from(User::class, 'u')
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }

    public function createAdmin(array $data): void
    {
        $user = new User();
        $user->setEmail($data['email']);
        $user->setPassword(password_hash($data['password'], \PASSWORD_DEFAULT));
        $user->setRoles($data['roles'] ?? ['ROLE_SUPER_ADMIN']);

        $this->em->persist($user);
        $this->em->flush();
    }
}
