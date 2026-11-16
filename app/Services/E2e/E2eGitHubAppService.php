<?php

namespace App\Services\E2e;

use App\Channels\GitHub\AppService;

final class E2eGitHubAppService extends AppService
{
    /** A synthetic token; no key, no JWT, no request. */
    public function getInstallationToken(int $installationId, bool $isTimeBounded = false): string
    {
        return 'e2e-installation-token';
    }
}
