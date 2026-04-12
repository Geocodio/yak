<?php

namespace App\Console\Commands;

use App\Services\GitHubAppService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('yak:git-credential {--stdin= : Override stdin input (for testing)}')]
#[Description('Git credential helper — outputs GitHub App installation token for HTTPS authentication')]
class GitCredentialHelperCommand extends Command
{
    public function handle(GitHubAppService $gitHubAppService): int
    {
        $input = $this->option('stdin') ?? (string) file_get_contents('php://stdin');

        if (! str_contains($input, 'host=github.com')) {
            return self::SUCCESS;
        }

        $installationId = (int) config('yak.channels.github.installation_id');

        if ($installationId === 0) {
            $this->components->error('GitHub installation ID is not configured.');

            return self::FAILURE;
        }

        $token = $gitHubAppService->getInstallationToken($installationId);

        $this->output->write("protocol=https\nhost=github.com\nusername=x-access-token\npassword={$token}\n\n");

        return self::SUCCESS;
    }
}
