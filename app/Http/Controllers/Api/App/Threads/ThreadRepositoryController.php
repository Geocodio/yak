<?php

namespace App\Http\Controllers\Api\App\Threads;

use App\Http\Controllers\Controller;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Services\Threads\Repos\RepositorySetupRetry;
use Illuminate\Http\JsonResponse;

/**
 * Retries the setup of an extra repository of a thread.
 */
class ThreadRepositoryController extends Controller
{
    public function retry(Thread $thread, ThreadRepository $repository, RepositorySetupRetry $setupRetry): JsonResponse
    {
        $setupRetry->retry($thread, $repository);

        return response()->json(['status' => 'retrying']);
    }
}
