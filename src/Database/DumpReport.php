<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Database;

/**
 * What the built-in dumper did (1.28+), recorded in MANIFEST v2 `dump`. No credentials, no argv.
 */
final readonly class DumpReport
{
    /**
     * @param list<string> $warnings table-rule warnings (names and reasons only)
     */
    public function __construct(
        public string $clientFlavour,
        public string $clientVersion,
        public int $passes,
        public bool $gtidPurgedOff,
        public array $warnings = [],
    ) {
    }

    /**
     * @return array{client: string, client_version: string, passes: int, single_transaction: bool, gtid_purged: string}
     */
    public function toManifest(): array
    {
        return [
            'client'             => $this->clientFlavour,
            'client_version'     => $this->clientVersion,
            'passes'             => $this->passes,
            'single_transaction' => true,
            'gtid_purged'        => $this->gtidPurgedOff ? 'off' : 'default',
        ];
    }
}
