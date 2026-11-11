<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\Thread;
use App\Models\User;
use App\Services\Ssh\InvalidSshKey;
use App\Services\Ssh\SandboxAuthorizedKeys;
use App\Services\Ssh\SandboxSshAddress;
use App\Services\Ssh\SshAccess;
use App\Services\Ssh\SshPublicKey;
use App\Services\Ssh\SshWake;
use App\Services\Ssh\TerminalTokens;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SshTerminalRedeemController extends Controller
{
    public function __invoke(
        Request $request,
        TerminalTokens $tokens,
        SshAccess $access,
        SshWake $wake,
        SandboxAuthorizedKeys $authorizedKeys,
    ): JsonResponse|Response {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:128'],
            'public_key' => ['required', 'string', 'max:2000'],
            'origin' => ['required', 'string', 'max:255'],
        ]);

        $grant = $tokens->redeem($data['token'], $data['origin']);
        $thread = $grant === null ? null : Thread::withoutGlobalScopes()->find($grant->threadId);
        $user = $grant === null ? null : User::query()->find($grant->userId);

        if ($thread === null || $user === null || ! $access->canOpenShell($user, $thread)) {
            return $this->denied();
        }

        try {
            $key = SshPublicKey::parse($data['public_key']);
        } catch (InvalidSshKey) {
            return $this->denied();
        }

        $result = $wake->handle($thread);

        if ($result->state !== 'ready') {
            return $this->denied();
        }

        $ttl = (int) config('yak.ssh.terminal_key_ttl');
        $authorizedKeys->addEphemeral($thread, $key, $ttl);

        return response()->json([
            'thread_id' => $thread->id,
            'address' => SandboxSshAddress::for($thread),
            'container' => $result->container,
            'ttl' => $ttl,
        ]);
    }

    private function denied(): Response
    {
        return response('', 403);
    }
}
