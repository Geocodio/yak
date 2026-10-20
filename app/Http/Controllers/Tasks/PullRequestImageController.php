<?php

namespace App\Http\Controllers\Tasks;

use App\Channels\GitHub\AppService;
use App\Enums\TaskMode;
use App\Http\Controllers\Controller;
use App\Models\Repository;
use App\Models\YakTask;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Serves an image from a review task's PR body. On a private repository a
 * `github.com/user-attachments/assets/{asset}` URL needs a browser session
 * on github.com, so the dashboard links here instead. GitHub's rendered PR
 * body holds a short-lived signed URL for each upload, which is fetched
 * with the app's token and streamed back.
 */
class PullRequestImageController extends Controller
{
    public function __invoke(YakTask $task, string $asset, AppService $github): Response
    {
        $context = json_decode((string) $task->context, true) ?: [];
        $prNumber = (int) ($context['pr_number'] ?? 0);
        abort_if($task->mode !== TaskMode::Review || $prNumber <= 0, 404);

        $repoFullName = Repository::where('slug', $task->repo)->value('github_full_name');
        abort_if($repoFullName === null, 404);

        // The signed URLs expire after about five minutes, so the rendered
        // body is reused only briefly by the other images on the same page.
        $bodyHtml = Cache::remember(
            "pr-body-html:{$repoFullName}:{$prNumber}",
            now()->addMinutes(3),
            fn (): string => (string) $github->getPullRequestBodyHtml(
                (int) config('yak.channels.github.installation_id'),
                $repoFullName,
                $prNumber,
            ),
        );

        $pattern = '#src="(https://private-user-images\.githubusercontent\.com/[^"]*' . preg_quote($asset, '#') . '[^"]*)"#';
        abort_unless(preg_match($pattern, $bodyHtml, $match) === 1, 404);

        $image = Http::timeout(15)->get(html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5));
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
