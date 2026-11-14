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
     * @param array{file: string, position: int, gtid: ?string}|null $binlogCoordinates 1.36+: what
     *                                                                                  `--source-data=2` recorded
     */
    public function __construct(
        public string $clientFlavour,
        public string $clientVersion,
        public int $passes,
        public bool $gtidPurgedOff,
        public array $warnings = [],
        public ?array $binlogCoordinates = null,
    ) {
    }

    /**
     * @return array{client: string, client_version: string, passes: int, single_transaction: bool, gtid_purged: string, source_data?: true}
     */
    public function toManifest(): array
    {
        $manifest = [
            'client'             => $this->clientFlavour,
            'client_version'     => $this->clientVersion,
            'passes'             => $this->passes,
            'single_transaction' => true,
            'gtid_purged'        => $this->gtidPurgedOff ? 'off' : 'default',
        ];
        if ($this->binlogCoordinates !== null) {
            // 1.36+: only when recordBinlogCoordinates asked for it, so other manifests stay as they were.
            $manifest['source_data'] = true;
        }

        return $manifest;
    }
}
