<?php

namespace App\Services\E2e;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class E2eGitHub
{
    public const int PULL_REQUEST_NUMBER = 7;

    /**
     * Fakes exactly the GitHub calls the journeys make (repository, existing pull requests, create pull request,
     * labels, `.yak/` files that do not exist, markdown) and refuses every other outbound request.
     */
    public static function fake(): void
    {
        $number = self::PULL_REQUEST_NUMBER;
        $log = static function (Request $request): void {
            Log::info('e2e github', ['method' => $request->method(), 'path' => parse_url($request->url(), PHP_URL_PATH)]);
        };

        // ponytail: Http::fake records every request for the life of the process; the run lasts at most 25 minutes, so the memory is bounded. Clear recorded requests periodically if long-lived workers grow.
        Http::fake([
            'api.github.com/repos/example/api/pulls*' => function (Request $request) use ($log, $number) {
                $log($request);

                return $request->method() === 'POST'
                    ? Http::response([
                        'number' => $number,
                        'html_url' => "https://github.com/example/api/pull/{$number}",
                        'title' => $request['title'] ?? 'Example pull request',
                        'node_id' => 'PR_e2e',
                    ], 201)
                    : Http::response([]);
            },
            'api.github.com/repos/example/api' => function (Request $request) use ($log) {
                $log($request);

                return Http::response(['full_name' => 'example/api', 'default_branch' => 'main', 'private' => true]);
            },
            'api.github.com/repos/example/api/issues/*/labels' => function (Request $request) use ($log) {
                $log($request);

                return Http::response([]);
            },
            'api.github.com/repos/example/api/contents/*' => function (Request $request) use ($log) {
                $log($request);

                return Http::response(['message' => 'Not Found'], 404);
            },
            'api.github.com/markdown' => function (Request $request) use ($log) {
                $log($request);

                return Http::response('<p>Example pull request</p>');
            },
        ]);
        Http::preventStrayRequests();
    }
}
