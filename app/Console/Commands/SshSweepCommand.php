<?php

namespace App\Console\Commands;

use App\Models\GithubKeySync;
use App\Services\Ssh\GitHubKeyDirectory;
use App\Services\Ssh\SandboxAuthorizedKeys;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

#[Signature('yak:ssh-sweep')]
#[Description('Refresh cached GitHub keys whose cache time has passed and drop expired terminal keys')]
class SshSweepCommand extends Command
{
    private const BATCH_SIZE = 50;

    public function handle(GitHubKeyDirectory $github, SandboxAuthorizedKeys $authorizedKeys): int
    {
        $positiveCutoff = now()->subSeconds((int) config('yak.ssh.github_ttl_seconds'));
        $negativeCutoff = now()->subSeconds((int) config('yak.ssh.github_negative_ttl_seconds'));

        $due = GithubKeySync::query()
            ->with('user')
            ->where(fn (Builder $query) => $query
                ->whereNull('checked_at')
                ->orWhere(fn (Builder $positive) => $positive->whereIn('status', ['ok', 'error'])->where('checked_at', '<', $positiveCutoff))
                ->orWhere(fn (Builder $negative) => $negative->whereIn('status', ['empty', 'unknown_account'])->where('checked_at', '<', $negativeCutoff)))
            ->orderBy('checked_at')
            ->limit(self::BATCH_SIZE)
            ->get();

        foreach ($due as $sync) {
            if ($sync->user !== null) {
                $github->refresh($sync->user);
            }
        }

        $authorizedKeys->sweep();

        $this->components->info("Refreshed GitHub keys for {$due->count()} user(s).");

        return self::SUCCESS;
    }
}
