<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Services\Ssh\SandboxSshAddress;
use App\Services\Ssh\SshWake;
use App\Services\Ssh\SshWakeResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SshWakeController extends Controller
{
    public function __invoke(Request $request, SshWake $wake): JsonResponse
    {
        $address = $request->validate(['address' => ['required', 'string', 'max:45']])['address'];
        $thread = SandboxSshAddress::threadFor($address);

        return response()->json(($thread === null ? new SshWakeResult('no_sandbox') : $wake->handle($thread))->toArray());
    }
}
