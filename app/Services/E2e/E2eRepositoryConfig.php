<?php

namespace App\Services\E2e;

use App\DataTransferObjects\ConfigSnapshot;
use App\Models\Repository;
use App\Services\RepositoryConfig;

/** The database values, as a repository without `.yak/` files gives; no request leaves the machine. */
final class E2eRepositoryConfig extends RepositoryConfig
{
    public function __construct() {}

    public function snapshot(Repository $repository): ConfigSnapshot
    {
        return ConfigSnapshot::unavailable();
    }
}
