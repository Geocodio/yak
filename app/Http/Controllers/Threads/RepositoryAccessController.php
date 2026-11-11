<?php

namespace App\Http\Controllers\Threads;

use App\Http\Controllers\Controller;
use App\Services\Threads\Repos\RepositoryAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Whether Yak can reach a repository, for the start dialog's picker.
 */
class RepositoryAccessController extends Controller
{
    public function __invoke(Request $request, RepositoryAccess $access): JsonResponse
    {
        $result = $access->check($request->validate(['repo' => ['required', 'string', 'max:255']])['repo']);

        return response()->json(['allowed' => $result->allowed, 'reason' => $result->reason]);
    }
}
