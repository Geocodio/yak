<?php

namespace App\Channels\GitHub;

use App\Models\GitHubInstallationToken;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class AppService
{
    private const TOKEN_BUFFER_SECONDS = 300;

    /**
     * Bot login for the configured GitHub App, shaped like `my-app[bot]`.
     *
     * Reads the explicit `GITHUB_APP_BOT_LOGIN` env var when set; otherwise
     * derives it from the App's `slug` (from `GET /app`) and caches for a
     * day. Falls back to the default if the API call fails so the review
     * path still skips cleanly even without network access.
     */
    public function appBotLogin(): string
    {
        $explicit = (string) config('yak.channels.github.app_bot_login_override', '');
        if ($explicit !== '') {
            return $explicit;
        }

        $default = (string) config('yak.channels.github.app_bot_login');

        return Cache::remember('github-app-bot-login', now()->addDay(), function () use ($default): string {
            try {
                $jwt = $this->generateJwt();

                $response = Http::withToken($jwt)
                    ->withHeaders(['Accept' => 'application/vnd.github+json'])
                    ->get('https://api.github.com/app');

                $slug = $response->json('slug');

                if (is_string($slug) && $slug !== '') {
                    return $slug . '[bot]';
                }
            } catch (\Throwable) {
                // Fall through to default
            }

            return $default;
        });
    }

    public function getInstallationToken(int $installationId): string
    {
        $cached = GitHubInstallationToken::where('installation_id', $installationId)->first();

        if ($cached && ! $cached->isExpired()) {
            return $cached->token;
        }

        return $this->requestInstallationToken($installationId);
    }

    /**
     * @return array<int, array{full_name: string, name: string, description: ?string, default_branch: string, clone_url: string, pushed_at: ?string, private: bool, language: ?string}>
     */
    public function listInstallationRepositories(int $installationId): array
    {
        $token = $this->getInstallationToken($installationId);

        $repos = [];
        $page = 1;

        do {
            $response = Http::withToken($token)
                ->withHeaders(['Accept' => 'application/vnd.github+json'])
                ->get('https://api.github.com/installation/repositories', [
                    'per_page' => 100,
                    'page' => $page,
                ]);

            $data = $response->json();
            $fetched = $data['repositories'] ?? [];

            foreach ($fetched as $repo) {
                $repos[] = [
                    'full_name' => $repo['full_name'],
                    'name' => $repo['name'],
                    'description' => $repo['description'] ?? null,
                    'default_branch' => $repo['default_branch'],
                    'clone_url' => $repo['clone_url'],
                    'pushed_at' => $repo['pushed_at'] ?? null,
                    'private' => (bool) ($repo['private'] ?? false),
                    'language' => $repo['language'] ?? null,
                ];
            }

            $total = $data['total_count'] ?? 0;
            $page++;
        } while (count($repos) < $total);

        // Sort by pushed_at desc; null pushed_at falls to the end.
        usort($repos, function (array $a, array $b): int {
            if ($a['pushed_at'] === $b['pushed_at']) {
                return 0;
            }
            if ($a['pushed_at'] === null) {
                return 1;
            }
            if ($b['pushed_at'] === null) {
                return -1;
            }

            return $b['pushed_at'] <=> $a['pushed_at'];
        });

        return $repos;
    }

    /**
     * Detect which CI system a repository uses by probing for config files.
     *
     * Returns 'drone' if .drone.yml is committed, 'github_actions' if
     * .github/workflows/ contains at least one file, or 'none' otherwise.
     */
    public function detectCiSystem(int $installationId, string $repoSlug): string
    {
        $token = $this->getInstallationToken($installationId);

        $drone = Http::withToken($token)
            ->withHeaders(['Accept' => 'application/vnd.github+json'])
            ->get("https://api.github.com/repos/{$repoSlug}/contents/.drone.yml");

        if ($drone->successful()) {
            return 'drone';
        }

        $workflows = Http::withToken($token)
            ->withHeaders(['Accept' => 'application/vnd.github+json'])
            ->get("https://api.github.com/repos/{$repoSlug}/contents/.github/workflows");

        if ($workflows->successful()) {
            $entries = $workflows->json();
            if (is_array($entries) && count($entries) > 0) {
                return 'github_actions';
            }
        }

        return 'none';
    }

    /**
     * Fetch a single repository's metadata (description, topics, etc.) from GitHub.
     *
     * @return array{description: ?string, topics: array<int, string>}|null
     */
    public function getRepository(int $installationId, string $repoSlug): ?array
    {
        $token = $this->getInstallationToken($installationId);

        $response = Http::withToken($token)
            ->withHeaders(['Accept' => 'application/vnd.github+json'])
            ->get("https://api.github.com/repos/{$repoSlug}");

        if (! $response->successful()) {
            return null;
        }

        return [
            'description' => $response->json('description'),
            'topics' => $response->json('topics', []),
        ];
    }

    /**
     * @param  array<string, mixed>  $prData
     * @return array<string, mixed>
     */
    public function createPullRequest(int $installationId, string $repoSlug, array $prData): array
    {
        $token = $this->getInstallationToken($installationId);

        $response = Http::withToken($token)
            ->withHeaders(['Accept' => 'application/vnd.github+json'])
            ->post("https://api.github.com/repos/{$repoSlug}/pulls", $prData);

        /** @var array<string, mixed> */
        return $response->json();
    }

    /**
     * @param  array<int, string>  $labels
     */
    public function addLabels(int $installationId, string $repoSlug, int $prNumber, array $labels): void
    {
        $token = $this->getInstallationToken($installationId);

        Http::withToken($token)
            ->withHeaders(['Accept' => 'application/vnd.github+json'])
            ->post("https://api.github.com/repos/{$repoSlug}/issues/{$prNumber}/labels", [
                'labels' => $labels,
            ]);
    }

    /**
     * Fetch the output text of failed check runs for a commit.
     *
     * GitHub's check_suite webhook payload doesn't include failure details
     * directly — they live on individual check_runs. This assembles a
     * human-readable summary suitable for an agent retry prompt.
     */
    public function getFailedCheckRunOutput(int $installationId, string $repoSlug, string $commitSha): ?string
    {
        $token = $this->getInstallationToken($installationId);

        $response = Http::withToken($token)
            ->withHeaders(['Accept' => 'application/vnd.github+json'])
            ->get("https://api.github.com/repos/{$repoSlug}/commits/{$commitSha}/check-runs", [
                'per_page' => 100,
            ]);

        if (! $response->successful()) {
            return null;
        }

        /** @var array<int, array{name: string, conclusion: ?string, html_url: string, output?: array{title?: ?string, summary?: ?string, text?: ?string}}> $runs */
        $runs = $response->json('check_runs', []);

        $sections = [];
        foreach ($runs as $run) {
            if (($run['conclusion'] ?? null) !== 'failure') {
                continue;
            }

            $parts = ["## {$run['name']} ({$run['html_url']})"];
            $output = $run['output'] ?? [];

            if (! empty($output['title'])) {
                $parts[] = "**{$output['title']}**";
            }
            if (! empty($output['summary'])) {
                $parts[] = $output['summary'];
            }
            if (! empty($output['text'])) {
                $parts[] = $output['text'];
            }

            $sections[] = implode("\n\n", $parts);
        }

        return empty($sections) ? null : implode("\n\n---\n\n", $sections);
    }

    /**
     * @return array<int, string>
     */
    public function getChangedFiles(int $installationId, string $repoSlug, int $prNumber): array
    {
        $token = $this->getInstallationToken($installationId);

        $response = Http::withToken($token)
            ->withHeaders(['Accept' => 'application/vnd.github+json'])
            ->get("https://api.github.com/repos/{$repoSlug}/pulls/{$prNumber}/files");

        /** @var array<int, array{filename: string}> $files */
        $files = $response->json();

        return array_map(fn (array $file): string => $file['filename'], $files);
    }

    /**
     * Compare two branches via the GitHub Compare API.
     *
     * Returns the list of changed file paths and the total LOC delta
     * (additions + deletions). Used by the post-CI jobs that need this
     * data without a local checkout.
     *
     * @return array{files: array<int, string>, loc_changed: int}
     */
    public function compareBranches(int $installationId, string $repoSlug, string $base, string $head): array
    {
        $token = $this->getInstallationToken($installationId);

        $response = Http::withToken($token)
            ->withHeaders(['Accept' => 'application/vnd.github+json'])
            ->get("https://api.github.com/repos/{$repoSlug}/compare/{$base}...{$head}");

        /** @var array{files?: array<int, array{filename: string, additions?: int|null, deletions?: int|null}>} $data */
        $data = $response->json();
        $files = $data['files'] ?? [];

        $loc = 0;
        $names = [];
        foreach ($files as $file) {
            $loc += ($file['additions'] ?? 0) + ($file['deletions'] ?? 0);
            $names[] = $file['filename'];
        }

        return ['files' => $names, 'loc_changed' => $loc];
    }

    public function generateJwt(): string
    {
        $appId = (string) config('yak.channels.github.app_id');
        $privateKey = (string) config('yak.channels.github.private_key');

        $now = time();
        $header = $this->base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $payload = $this->base64UrlEncode(json_encode([
            'iat' => $now - 60,
            'exp' => $now + (10 * 60),
            'iss' => $appId,
        ], JSON_THROW_ON_ERROR));

        $dataToSign = "{$header}.{$payload}";
        openssl_sign($dataToSign, $signature, $privateKey, OPENSSL_ALGO_SHA256);

        return "{$header}.{$payload}.{$this->base64UrlEncode($signature)}";
    }

    /**
     * List the files changed in a PR, including each file's patch. Used
     * to filter review findings down to lines that actually live inside a
     * diff hunk — GitHub rejects the whole review otherwise.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listPullRequestFiles(int $installationId, string $repoSlug, int $prNumber): array
    {
        $token = $this->getInstallationToken($installationId);
        $results = [];
        $page = 1;

        while (true) {
            $response = Http::withToken($token)
                ->withHeaders(['Accept' => 'application/vnd.github+json'])
                ->get("https://api.github.com/repos/{$repoSlug}/pulls/{$prNumber}/files", [
                    'per_page' => 100,
                    'page' => $page,
                ]);

            $batch = $response->json();
            if (! is_array($batch) || $batch === []) {
                break;
            }

            $results = array_merge($results, $batch);

            if (count($batch) < 100) {
                break;
            }

            $page++;
        }

        return $results;
    }

    /**
     * @param  array<int, array{path: string, line: int, body: string}>  $comments
     * @return array<string, mixed>
     */
    public function createPullRequestReview(
        int $installationId,
        string $repoSlug,
        int $prNumber,
        string $body,
        string $event,
        array $comments,
    ): array {
        $token = $this->getInstallationToken($installationId);

        $response = Http::withToken($token)
            ->withHeaders(['Accept' => 'application/vnd.github+json'])
            ->post("https://api.github.com/repos/{$repoSlug}/pulls/{$prNumber}/reviews", [
                'body' => $body,
                'event' => $event,
                'comments' => $comments,
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException(sprintf(
                'GitHub rejected pull_request review (status %d): %s',
                $response->status(),
                (string) $response->body(),
            ));
        }

        /** @var array<string, mixed> */
        return $response->json();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listOpenPullRequests(int $installationId, string $repoSlug): array
    {
        $token = $this->getInstallationToken($installationId);
        $results = [];
        $page = 1;

        while (true) {
            $response = Http::withToken($token)
                ->withHeaders(['Accept' => 'application/vnd.github+json'])
                ->get("https://api.github.com/repos/{$repoSlug}/pulls", [
                    'state' => 'open',
                    'per_page' => 100,
                    'page' => $page,
                ]);

            $batch = $response->json();
            if (! is_array($batch) || $batch === []) {
                break;
            }

            $results = array_merge($results, $batch);

            if (count($batch) < 100) {
                break;
            }

            $page++;
        }

        return $results;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPullRequest(int $installationId, string $repoSlug, int $prNumber): array
    {
        $token = $this->getInstallationToken($installationId);

        $response = Http::withToken($token)
            ->withHeaders(['Accept' => 'application/vnd.github+json'])
            ->get("https://api.github.com/repos/{$repoSlug}/pulls/{$prNumber}");

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    /**
     * Patch an existing PR. Typically used to edit the body (e.g. to append
     * a Director's Cut link once the render completes asynchronously).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function updatePullRequest(int $installationId, string $repoSlug, int $prNumber, array $data): array
    {
        $token = $this->getInstallationToken($installationId);

        $response = Http::withToken($token)
            ->withHeaders(['Accept' => 'application/vnd.github+json'])
            ->patch("https://api.github.com/repos/{$repoSlug}/pulls/{$prNumber}", $data);

        if (! $response->successful()) {
            throw new \RuntimeException(sprintf(
                'GitHub rejected pull_request PATCH (status %d): %s',
                $response->status(),
                (string) $response->body(),
            ));
        }

        /** @var array<string, mixed> */
        return $response->json();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listCommentReactions(int $installationId, string $repoSlug, int $commentId): array
    {
        $token = $this->getInstallationToken($installationId);

        $response = Http::withToken($token)
            ->withHeaders(['Accept' => 'application/vnd.github+json'])
            ->get("https://api.github.com/repos/{$repoSlug}/pulls/comments/{$commentId}/reactions");

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    public function dismissPullRequestReview(
        int $installationId,
        string $repoSlug,
        int $prNumber,
        int $reviewId,
        string $message,
    ): void {
        $token = $this->getInstallationToken($installationId);

        Http::withToken($token)
            ->withHeaders(['Accept' => 'application/vnd.github+json'])
            ->put("https://api.github.com/repos/{$repoSlug}/pulls/{$prNumber}/reviews/{$reviewId}/dismissals", [
                'message' => $message,
                'event' => 'DISMISS',
            ]);
    }

    public function createDeployment(
        int $installationId,
        string $repoSlug,
        string $ref,
        string $environment,
        string $description = '',
    ): int {
        $token = $this->getInstallationToken($installationId);

        $response = Http::withToken($token)
            ->withHeaders(['Accept' => 'application/vnd.github+json'])
            ->post("https://api.github.com/repos/{$repoSlug}/deployments", [
                'ref' => $ref,
                'environment' => $environment,
                'description' => $description,
                'transient_environment' => true,
                'auto_merge' => false,
                'required_contexts' => [],
            ])
            ->throw();

        return (int) $response->json('id');
    }

    public function createDeploymentStatus(
        int $installationId,
        string $repoSlug,
        int $deploymentId,
        string $state,
        ?string $environmentUrl = null,
        ?string $logUrl = null,
        string $description = '',
    ): void {
        $token = $this->getInstallationToken($installationId);

        $payload = array_filter([
            'state' => $state,
            'environment_url' => $environmentUrl,
            'log_url' => $logUrl,
            'description' => $description,
        ]);

        Http::withToken($token)
            ->withHeaders(['Accept' => 'application/vnd.github+json'])
            ->post("https://api.github.com/repos/{$repoSlug}/deployments/{$deploymentId}/statuses", $payload)
            ->throw();
    }

    public function deleteDeployment(int $installationId, string $repoSlug, int $deploymentId): void
    {
        $token = $this->getInstallationToken($installationId);

        Http::withToken($token)
            ->withHeaders(['Accept' => 'application/vnd.github+json'])
            ->delete("https://api.github.com/repos/{$repoSlug}/deployments/{$deploymentId}")
            ->throw();
    }

    private function requestInstallationToken(int $installationId): string
    {
        $jwt = $this->generateJwt();

        $response = Http::withToken($jwt)
            ->withHeaders(['Accept' => 'application/vnd.github+json'])
            ->throw()
            ->post("https://api.github.com/app/installations/{$installationId}/access_tokens");

        $token = $response->json('token');
        $expiresAt = $response->json('expires_at');

        if (! is_string($token) || $token === '' || ! is_string($expiresAt) || $expiresAt === '') {
            throw new \RuntimeException("GitHub returned an empty installation token for installation {$installationId}");
        }

        GitHubInstallationToken::updateOrCreate(
            ['installation_id' => $installationId],
            [
                'token' => $token,
                'expires_at' => now()->parse($expiresAt)->subSeconds(self::TOKEN_BUFFER_SECONDS),
            ]
        );

        return $token;
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
