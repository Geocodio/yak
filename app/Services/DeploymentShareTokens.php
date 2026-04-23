<?php

namespace App\Services;

use App\Models\BranchDeployment;
use Illuminate\Support\Str;

class DeploymentShareTokens
{
    public function mint(BranchDeployment $deployment, int $expiresInDays): string
    {
        $max = (int) config('yak.deployments.share.max_days');
        $days = max(1, min($expiresInDays, $max));

        $token = Str::random(32);
        $hash = hash('sha256', $token);

        $deployment->update([
            'public_share_token_hash' => $hash,
            'public_share_expires_at' => now()->addDays($days),
        ]);

        return $token;
    }

    public function verify(BranchDeployment $deployment, string $candidate): bool
    {
        if ($deployment->public_share_token_hash === null) {
            return false;
        }
        if ($deployment->public_share_expires_at?->isPast()) {
            return false;
        }

        return hash_equals($deployment->public_share_token_hash, hash('sha256', $candidate));
    }

    public function revoke(BranchDeployment $deployment): void
    {
        $deployment->update([
            'public_share_token_hash' => null,
            'public_share_expires_at' => null,
        ]);
    }

    public function cookieValue(BranchDeployment $deployment): string
    {
        return hash_hmac(
            'sha256',
            $deployment->id . ':' . ($deployment->public_share_token_hash ?? ''),
            (string) config('app.key'),
        );
    }

    public function verifyCookie(BranchDeployment $deployment, string $candidateCookie): bool
    {
        if ($deployment->public_share_token_hash === null) {
            return false;
        }
        if ($deployment->public_share_expires_at?->isPast()) {
            return false;
        }

        return hash_equals($this->cookieValue($deployment), $candidateCookie);
    }
}
