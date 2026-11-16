<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Services\Ssh\GitHubKeyDirectory;
use App\Services\Ssh\SshAccess;
use App\Services\Ssh\SshKeyDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SshAuthorizedKeysController extends Controller
{
    public function __invoke(Request $request, SshKeyDirectory $directory, GitHubKeyDirectory $github, SshAccess $access): JsonResponse
    {
        $fingerprint = $request->validate(['fingerprint' => ['required', 'string', 'max:200']])['fingerprint'];

        $owner = $directory->userForFingerprint($fingerprint);

        if ($owner !== null) {
            // Bounded by the cache ttl, so a busy jump host does not hammer GitHub.
            $github->refresh($owner);
        }

        return response()->json(['lines' => $access->authorizedKeyLines($fingerprint)]);
    }
}
