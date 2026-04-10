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
