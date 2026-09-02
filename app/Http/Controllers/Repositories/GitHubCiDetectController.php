<?php

namespace App\Http\Controllers\Repositories;

use App\Channels\GitHub\AppService as GitHubAppService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class GitHubCiDetectController extends Controller
{
    /**
     * Detects which CI system a GitHub repository uses, for the create-mode
     * form to pre-select once a repo is picked. Ported from
     * `RepoForm::detectCiSystem()`.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $fullName = $request->string('full_name')->toString();

        return response()->json(['ciSystem' => $this->detectCiSystem($fullName)]);
    }

    private function detectCiSystem(string $fullName): string
    {
        if ($fullName === '') {
            return 'none';
        }

        try {
            $installationId = (int) config('yak.channels.github.installation_id');

            if (! $installationId) {
                return 'none';
            }

            return Cache::remember(
                "github-ci-detect:{$fullName}",
                300,
                fn (): string => app(GitHubAppService::class)->detectCiSystem($installationId, $fullName),
            );
        } catch (\Throwable) {
            return 'none';
        }
    }
}
