<?php

namespace App\Http\Controllers\Api\Cli;

use App\Enums\ThreadOwnerSide;
use App\Http\Controllers\Api\Cli\Concerns\ResolvesCliThread;
use App\Http\Controllers\Controller;
use App\Http\Responses\CliError;
use App\Services\Ssh\GitHubKeyDirectory;
use App\Services\Ssh\SandboxSshAddress;
use App\Services\Ssh\SshAccess;
use App\Services\Ssh\SshKeyDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tells `yak ssh` how to reach a thread's sandbox: through the jump host, to the sandbox's loopback wake address.
 */
class SshLookupController extends Controller
{
    use ResolvesCliThread;

    public function show(Request $request, string $thread, SshAccess $access, SshKeyDirectory $keys, GitHubKeyDirectory $github): JsonResponse
    {
        $thread = $this->cliThread($request, $thread);
        $user = $this->cliDevice($request)->user;

        abort_if($user === null, 401);

        if ($thread->owner_side === ThreadOwnerSide::Device) {
            return CliError::make('thread_owned_by_device', 'This thread runs on your computer. Teleport it up to get a shell in a sandbox.', 409);
        }

        $refusal = match ($access->denial($user, $thread)) {
            null => null,
            'forbidden' => CliError::make('forbidden', 'You can see this thread but cannot drive it.', 403),
            'thread_closed' => CliError::make('thread_closed', 'This thread is closed.', 409),
            'sandbox_destroyed' => CliError::make('sandbox_destroyed', 'This sandbox was destroyed. Restore it from the thread page.', 409, ['thread_url' => route('threads.show', $thread)]),
            default => CliError::make('no_sandbox', 'This thread has no sandbox yet.', 409),
        };

        if ($refusal !== null) {
            return $refusal;
        }

        $github->refresh($user);

        if (! $keys->hasKeys($user)) {
            return CliError::make('no_ssh_key', 'Add an SSH key to your Yak account first.', 409, ['settings_url' => route('settings.ssh-keys')]);
        }

        $jumpHost = (string) config('yak.ssh.jump_host');

        if ($jumpHost === '') {
            return CliError::make('ssh_unavailable', 'SSH access is not set up on this Yak.', 503);
        }

        return response()->json([
            'jump_host' => [
                'host' => $jumpHost,
                'port' => (int) config('yak.ssh.jump_port'),
                'user' => (string) config('yak.ssh.jump_user'),
            ],
            'alias' => $thread->name,
            'target' => ['host' => SandboxSshAddress::for($thread), 'port' => 22, 'user' => 'yak'],
            'host_key_fingerprints' => array_values((array) config('yak.ssh.jump_host_key_fingerprints')),
        ]);
    }
}
