<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Services\Ssh\SandboxSshAddress;
use App\Services\Threads\ThreadSandbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SshActivityController extends Controller
{
    public function __invoke(Request $request, ThreadSandbox $sandbox): JsonResponse
    {
        $address = $request->validate(['address' => ['required', 'string', 'max:45']])['address'];
        $thread = SandboxSshAddress::threadFor($address);

        if ($thread === null) {
            return response()->json(['state' => 'no_sandbox']);
        }

        $sandbox->touch($thread);

        return response()->json(['state' => 'ok']);
    }
}
