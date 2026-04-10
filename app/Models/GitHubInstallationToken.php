<?php

namespace App\Models;

use Database\Factories\GitHubInstallationTokenFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class GitHubInstallationToken extends Model
{
    /** @use HasFactory<GitHubInstallationTokenFactory> */
    use HasFactory;

    protected $table = 'github_installation_tokens';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'expires_at' => 'datetime',
        ];
    }

    public function isExpired(): bool
    {
        /** @var Carbon $expiresAt */
        $expiresAt = $this->expires_at;

        return $expiresAt->isPast();
    }
}
