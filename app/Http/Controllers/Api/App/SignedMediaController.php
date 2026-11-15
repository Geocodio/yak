<?php

namespace App\Http\Controllers\Api\App;

use App\Channels\GitHub\AppService;
use App\Http\Controllers\Controller;
use App\Http\Controllers\TaskAttachmentController;
use App\Http\Controllers\Tasks\PullRequestImageController;
use App\Models\TaskAttachment;
use App\Models\YakTask;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves images and files of task detail to the app's web view. The signature
 * is the only credential: these routes sit outside the session and outside
 * the phone token, and the `signed` middleware answers 403 for a missing,
 * changed or expired signature. The bytes and headers are the web routes'.
 */
class SignedMediaController extends Controller
{
    public function attachment(Request $request, int $attachment): BinaryFileResponse
    {
        return app(TaskAttachmentController::class)($request, TaskAttachment::query()->findOrFail($attachment));
    }

    public function pullRequestImage(string $task, string $asset, AppService $github): Response
    {
        $run = YakTask::query()->where('public_id', $task)->firstOrFail();

        return app(PullRequestImageController::class)($run, $asset, $github, isTimeBounded: true);
    }
}
