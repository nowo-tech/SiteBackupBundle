<?php

declare(strict_types=1);

namespace Nowo\SiteBackupBundle\Remote;

/**
 * How {@see RemoteBackupPublisher::publish()} checks an upload.
 */
enum VerifyMode: string
{
    /** HEAD: the stored size equals the local size. */
    case Size = 'size';

    /** HEAD: size, plus the `nowo-sha256` metadata when the target keeps metadata (default). */
    case SizeAndMetadata = 'size_metadata';

    /** Reads the whole object back and hashes it (one download: for weekly / manual checks). */
    case FullRehash = 'full';
}
