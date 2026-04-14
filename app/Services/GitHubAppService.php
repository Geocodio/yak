<?php

namespace App\Services;

use App\Models\GitHubInstallationToken;
use Illuminate\Support\Facades\Http;

class GitHubAppService
{
    private const TOKEN_BUFFER_SECONDS = 300;

    public function getInstallationToken(int $installationId): string
    {
        $cached = GitHubInstallationToken::where('installation_id', $installationId)->first();

        if ($cached && ! $cached->isExpired()) {
            return $cached->token;
        }

        return $this->requestInstallationToken($installationId);
    }

    /**
     * @return array<int, array{full_name: string, name: string, description: ?string, default_branch: string, clone_url: string, pushed_at: ?string}>
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
                ];
            }

            $total = $data['total_count'] ?? 0;
            $page++;
        } while (count($repos) < $total);

        return $repos;
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
     * @return array{number: int, html_url: string}
     */
    public function createPullRequest(int $installationId, string $repoSlug, array $prData): array
    {
        $token = $this->getInstallationToken($installationId);

        $response = Http::withToken($token)
            ->withHeaders(['Accept' => 'application/vnd.github+json'])
            ->post("https://api.github.com/repos/{$repoSlug}/pulls", $prData);

        /** @var array{number: int, html_url: string} */
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

    private function requestInstallationToken(int $installationId): string
    {
        $jwt = $this->generateJwt();

        $response = Http::withToken($jwt)
            ->withHeaders(['Accept' => 'application/vnd.github+json'])
            ->post("https://api.github.com/app/installations/{$installationId}/access_tokens");

        /** @var string $token */
        $token = $response->json('token');

        /** @var string $expiresAt */
        $expiresAt = $response->json('expires_at');

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
