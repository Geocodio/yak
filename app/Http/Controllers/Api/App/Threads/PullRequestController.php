<?php

namespace App\Http\Controllers\Api\App\Threads;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\OpenAppPullRequestRequest;
use App\Models\Thread;
use App\Services\Threads\PullRequests\OpenPullRequest;
use Illuminate\Http\JsonResponse;

class PullRequestController extends Controller
{
    /**
     * 202 while the job opens the pull request; 200 once it is open or while a co-owner holds it.
     */
    public function store(OpenAppPullRequestRequest $request, Thread $thread, OpenPullRequest $openPullRequest): JsonResponse
    {
        $status = $openPullRequest->open($thread, $request->user() ?? abort(401), $request->acknowledgedPrivatePublish());

        return response()->json(['status' => $status], $status === 'opening' ? 202 : 200);
    }
}
