<?php

namespace App\Http\Controllers\Tasks;

use App\Channels\GitHub\AppService;
use App\Enums\TaskMode;
use App\Http\Controllers\Controller;
use App\Models\Repository;
use App\Models\YakTask;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Serves an image from a review task's PR body. On a private repository a
 * `github.com/user-attachments/assets/{asset}` URL needs a browser session
 * on github.com, so the dashboard links here instead. The task's stored PR
 * body is rendered by GitHub with the app's token, which yields a
 * short-lived signed URL for each upload, and the image is streamed back.
 * Rendering the stored body keeps working after the author edits the PR
 * and replaces its images.
 */
class PullRequestImageController extends Controller
{
    public function __invoke(YakTask $task, string $asset, AppService $github, bool $isTimeBounded = false): Response
    {
        $context = json_decode((string) $task->context, true) ?: [];
        $body = (string) ($context['body'] ?? '');
        abort_if($task->mode !== TaskMode::Review || ! str_contains($body, $asset), 404);

        $repoFullName = Repository::where('slug', $task->repo)->value('github_full_name');
        abort_if($repoFullName === null, 404);

        // The signed URLs expire after about five minutes, so the rendered
        // body is reused only briefly by the other images on the same page.
        try {
            return $this->streamImage($task, $asset, $body, $repoFullName, $github, $isTimeBounded);
        } catch (ConnectionException $exception) {
            // The app's web view cannot wait on GitHub; the web page keeps its own behavior.
            throw_unless($isTimeBounded, $exception);

            abort(503, 'GitHub did not answer in time.');
        }
    }

    private function streamImage(YakTask $task, string $asset, string $body, string $repoFullName, AppService $github, bool $isTimeBounded): Response
    {
        $cacheKey = "pr-body-html:{$task->id}";
        $bodyHtml = Cache::get($cacheKey);

        if (! is_string($bodyHtml)) {
            $bodyHtml = (string) $github->renderMarkdown(
                (int) config('yak.channels.github.installation_id'),
                $repoFullName,
                $body,
                $isTimeBounded,
            );

            // A failed render is empty; caching it would 404 every image of the task for minutes.
            if ($bodyHtml !== '') {
                Cache::put($cacheKey, $bodyHtml, now()->addMinutes(3));
            }
        }

        $pattern = '#src="(https://private-user-images\.githubusercontent\.com/[^"]*' . preg_quote($asset, '#') . '[^"]*)"#';
        abort_unless(preg_match($pattern, $bodyHtml, $match) === 1, 404);

        $image = Http::when($isTimeBounded, fn (PendingRequest $request) => $request->connectTimeout(2)->timeout(3), fn (PendingRequest $request) => $request->timeout(15))
            ->get(html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5));
        $contentType = (string) $image->header('Content-Type');
        abort_unless($image->successful() && Str::startsWith($contentType, ['image/', 'video/']), 404);

        return response($image->body(), 200, [
            'Content-Type' => $contentType,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }
}
