<?php

namespace App\Channels\Slack;

use App\Enums\PreviewRepairVerdict;
use App\Models\BranchDeployment;
use App\Models\PreviewRepair;
use App\Models\PreviewRepairDeployment;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\PreviewRepair\PreviewFailureReporter;
use App\Services\PreviewRepair\PreviewRepairRecipients;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Tells people about failed previews and the repairs Yak opens for them:
 * direct messages to the affected authors and posts in the feed channel.
 *
 * Every method is best effort. A missing bot token or feed channel skips the
 * message with a log line, and a Slack or GitHub error is reported, never
 * thrown, so messaging never breaks a deployment or a task.
 */
class PreviewRepairMessenger
{
    public function __construct(
        private readonly PreviewRepairRecipients $recipients,
        private readonly UserLookup $users,
    ) {}

    public function repairOpened(PreviewRepair $previewRepair): void
    {
        $this->bestEffort(function () use ($previewRepair): void {
            $attachments = $this->unnotifiedAttachments($previewRepair);
            $this->sendDirectMessages($previewRepair, $attachments, fn (array $deployments): array => PreviewRepairBlocks::repairOpened($previewRepair, $deployments));
            $this->postToFeed(PreviewRepairBlocks::repairOpenedFeed($previewRepair, $previewRepair->attachments()->count()));
            $previewRepair->update(['last_notified_at' => now()]);
        });
    }

    public function verdictReached(PreviewRepair $previewRepair): void
    {
        $this->bestEffort(function () use ($previewRepair): void {
            if ($previewRepair->verdict === PreviewRepairVerdict::Branch) {
                $this->sendDirectMessages($previewRepair, $this->unnotifiedAttachments($previewRepair), fn (array $deployments): array => PreviewRepairBlocks::branchCause($previewRepair, $deployments));
            } elseif (in_array($previewRepair->verdict, [PreviewRepairVerdict::Infrastructure, PreviewRepairVerdict::Unknown], true)) {
                $this->postToFeed(PreviewRepairBlocks::unresolvedFeed($previewRepair));
            }

            $previewRepair->update(['last_notified_at' => now()]);
        });
    }

    public function reminder(PreviewRepair $previewRepair): void
    {
        $this->bestEffort(function () use ($previewRepair): void {
            $attachments = $this->unnotifiedAttachments($previewRepair);
            $this->sendDirectMessages($previewRepair, $attachments, fn (array $deployments): array => PreviewRepairBlocks::reminder($previewRepair, count($deployments)));
            $this->postToFeed(PreviewRepairBlocks::reminder($previewRepair, max(1, $attachments->count())));
        });
    }

    public function capReached(Repository $repository): void
    {
        $this->bestEffort(fn () => $this->postToFeed(PreviewRepairBlocks::capReached($repository, PreviewFailureReporter::DAILY_CAP_PER_REPOSITORY)));
    }

    public function repairPreviewFailed(BranchDeployment $deployment): void
    {
        $this->bestEffort(function () use ($deployment): void {
            $task = YakTask::query()
                ->where('source', PreviewFailureReporter::SOURCE)
                ->where('repo', $deployment->repository->slug)
                ->where('branch_name', $deployment->branch_name)
                ->latest('id')
                ->first();

            $this->postToFeed(PreviewRepairBlocks::repairPreviewFailed(
                $task?->pr_url,
                $task?->pr_number,
                (string) $deployment->failure_reason,
            ));
        });
    }

    /**
     * @param  list<int>  $deploymentIds  the previews being rebuilt
     */
    public function rebuilt(PreviewRepair $previewRepair, array $deploymentIds): void
    {
        $this->bestEffort(function () use ($previewRepair, $deploymentIds): void {
            $attachments = $previewRepair->attachments()->with('deployment')->whereIn('branch_deployment_id', $deploymentIds)->orderBy('id')->get();
            $this->sendDirectMessages($previewRepair, $attachments, fn (array $deployments): array => PreviewRepairBlocks::rebuilt($previewRepair, $deployments), markNotified: false);
        });
    }

    /**
     * @return Collection<int, PreviewRepairDeployment>
     */
    private function unnotifiedAttachments(PreviewRepair $previewRepair): Collection
    {
        return $previewRepair->attachments()->with('deployment')->whereNull('notified_at')->orderBy('id')->get();
    }

    /**
     * @param  Collection<int, PreviewRepairDeployment>  $attachments
     * @param  callable(list<BranchDeployment>): array{text: string, blocks: list<array<string, mixed>>}  $build
     */
    private function sendDirectMessages(PreviewRepair $previewRepair, Collection $attachments, callable $build, bool $markNotified = true): void
    {
        if ($attachments->isEmpty()) {
            return;
        }

        foreach ($this->recipients->for($previewRepair, $attachments) as $recipient) {
            $slackUserId = $this->users->slackUserIdFor($recipient['user']);

            if ($slackUserId === null) {
                continue;
            }

            $this->post($slackUserId, $build($recipient['deployments']));
        }

        if ($markNotified) {
            PreviewRepairDeployment::query()->whereKey($attachments->modelKeys())->update(['notified_at' => now()]);
        }
    }

    /**
     * @param  array{text: string, blocks: list<array<string, mixed>>}  $message
     */
    private function postToFeed(array $message): void
    {
        $channel = (string) config('yak.channels.slack.feed_channel');

        if ($channel === '') {
            Log::channel('yak')->info('Preview repair feed post skipped, no feed channel is set');

            return;
        }

        $this->post($channel, $message);
    }

    /**
     * @param  array{text: string, blocks: list<array<string, mixed>>}  $message
     */
    private function post(string $channel, array $message): void
    {
        $token = (string) config('yak.channels.slack.bot_token');

        if ($token === '') {
            Log::channel('yak')->info('Preview repair message skipped, Slack bot token is not set');

            return;
        }

        $response = Http::withToken($token)->post('https://slack.com/api/chat.postMessage', [
            'channel' => $channel,
            'text' => $message['text'],
            'blocks' => $message['blocks'],
        ]);

        if ($response->json('ok') !== true) {
            Log::channel('yak')->warning('Preview repair Slack message was not delivered', [
                'channel' => $channel,
                'error' => $response->json('error'),
            ]);
        }
    }

    private function bestEffort(\Closure $send): void
    {
        try {
            $send();
        } catch (\Throwable $exception) {
            report($exception);
        }
    }
}
