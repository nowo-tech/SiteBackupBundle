<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Model;

use Nowo\SiteBackupBundle\Service\SiteBackupManager;

/**
 * Options of {@see SiteBackupManager::importArchive()} (1.29+).
 */
final readonly class ImportOptions
{
    /**
     * @param bool $allowPlaintext accept an archive that is not fully encrypted although a password was given
     *                             (EO-M1: off, a plaintext or mixed archive is a downgrade)
     * @param array<string, mixed>|null $sidecar the archive's `.backup.json` / `.meta.json`, for its signature
     * @param bool|null $requireSignature null = `backup.signing.required`
     * @param bool $deleteAfterRestore remove the imported plaintext copy once it has been restored (Q-O6)
     */
    public function __construct(
        public bool $allowPlaintext = false,
        public ?array $sidecar = null,
        public ?bool $requireSignature = null,
        public bool $deleteAfterRestore = true,
    ) {
    }
}
