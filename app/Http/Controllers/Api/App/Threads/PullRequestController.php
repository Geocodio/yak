<?php

namespace App\Http\Controllers\Api\App\Threads;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\OpenAppPullRequestRequest;
use App\Http\Requests\Api\App\ReorderAppMergeOrderRequest;
use App\Models\Thread;
use App\Services\Threads\PullRequests\AskToFix;
use App\Services\Threads\PullRequests\MergeOrder;
use App\Services\Threads\PullRequests\OpenPullRequest;
use App\Services\Threads\PullRequests\ThreadPullRequestsPanel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

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

    /**
     * The pull request panel: state, banner, one entry per pull request in merge order, and the repositories without one.
     */
    public function index(Request $request, Thread $thread, ThreadPullRequestsPanel $panel): JsonResponse
    {
        // A hidden thread answers exactly like a missing one.
        abort_unless(Gate::allows('view', $thread), 404);

        return response()->json($panel->build($thread, $request->user()));
    }

    /**
     * Saves the merge order; the same list twice leaves the same order.
     */
    public function order(ReorderAppMergeOrderRequest $request, Thread $thread, MergeOrder $mergeOrder): JsonResponse
    {
        /** @var list<int> $order */
        $order = array_map('intval', $request->validated('order'));
        $mergeOrder->set($thread, $order);

        return response()->json(['status' => 'updated']);
    }

    /**
     * Asks Yak to fix failing CI on one pull request; a repeat does nothing more.
     */
    public function fix(Thread $thread, int $repository, AskToFix $askToFix): JsonResponse
    {
        $askToFix->request($thread, $repository);

        return response()->json(['status' => 'sent']);
    }
}
