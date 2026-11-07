<?php

namespace App\Services\PreviewRepair;

use App\Channels\GitHub\AppService;
use App\Models\BranchDeployment;
use App\Models\PreviewRepair;
use App\Models\PreviewRepairDeployment;
use App\Models\User;
use App\Services\GitHubUserResolver;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * The people to tell about a repair: each failed preview's pull request
 * author, grouped so a person with several failed previews hears once.
 *
 * A preview with no pull request, an author who has not linked GitHub, or a
 * failed GitHub lookup falls back to the repository's default responsible
 * user. People who turned direct messages off are left out.
 */
class PreviewRepairRecipients
{
    public function __construct(
        private readonly AppService $github,
        private readonly GitHubUserResolver $users,
    ) {}

    /**
     * @param  EloquentCollection<int, PreviewRepairDeployment>|null  $attachments  defaults to every attached preview
     * @return Collection<int, array{user: User, deployments: non-empty-list<BranchDeployment>}>
     */
    public function for(PreviewRepair $previewRepair, ?EloquentCollection $attachments = null): Collection
    {
        $attachments ??= $previewRepair->attachments()->with('deployment')->orderBy('id')->get();
        $fallback = $previewRepair->repository->defaultResponsibleUser;

        $recipients = [];

        foreach ($attachments as $attachment) {
            $deployment = $attachment->deployment;

            if ($deployment === null) {
                continue;
            }

            $user = $this->author($previewRepair, $deployment) ?? $fallback;

            if ($user === null || ! $user->direct_messages_enabled) {
                continue;
            }

            $recipients[$user->id] ??= ['user' => $user, 'deployments' => []];
            $recipients[$user->id]['deployments'][] = $deployment;
        }

        return collect(array_values($recipients));
    }

    private function author(PreviewRepair $previewRepair, BranchDeployment $deployment): ?User
    {
        if ($deployment->pr_number === null) {
            return null;
        }

        try {
            $pullRequest = $this->github->getPullRequest(
                (int) config('yak.channels.github.installation_id'),
                (string) $previewRepair->repository->github_full_name,
                (int) $deployment->pr_number,
            );
        } catch (\Throwable $exception) {
            report($exception);

            return null;
        }

        $login = $pullRequest['user']['login'] ?? null;

        return is_string($login) && $login !== '' ? $this->users->findByLogin($login) : null;
    }
}
