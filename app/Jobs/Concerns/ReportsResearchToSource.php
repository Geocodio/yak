<?php

namespace App\Jobs\Concerns;

use App\Channels\Linear\NotificationDriver as LinearNotificationDriver;
use App\Channels\Slack\BlockFormatter as SlackBlockFormatter;
use App\Models\Artifact;
use App\Services\IncusSandboxManager;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Shared by the jobs that run a read-only research turn: collecting the
 * optional HTML report from the sandbox and posting the answer back to the
 * channel the task came from. Expects a `$task` property on the job.
 */
trait ReportsResearchToSource
{
    protected function collectHtmlArtifact(IncusSandboxManager $sandbox, string $containerName): ?Artifact
    {
        $workspacePath = IncusSandboxManager::workspacePath();
        $remotePath = "{$workspacePath}/.yak-artifacts/research.html";

        if (! $sandbox->fileExists($containerName, $remotePath)) {
            return null;
        }

        // Pull the artifact from the sandbox to local storage
        $storagePath = "{$this->task->id}/research.html";
        $localPath = Storage::disk('artifacts')->path($storagePath);

        $localDir = dirname($localPath);
        if (! is_dir($localDir)) {
            mkdir($localDir, 0755, true);
        }

        $sandbox->pullFile($containerName, $remotePath, $localPath);

        /** Research artifacts sit outside the video pipeline, so they carry no role. */
        return Artifact::create([
            'yak_task_id' => $this->task->id,
            'type' => 'research',
            'role' => null,
            'filename' => 'research.html',
            'disk_path' => $storagePath,
            'size_bytes' => filesize($localPath) ?: 0,
        ]);
    }

    /**
     * Auth-gated viewer URL — same one we attach to Linear issues. The
     * controller redirects unauthenticated visitors through the dashboard
     * login, so a Slack click works once the user has a session.
     */
    protected function viewerUrl(Artifact $artifact): string
    {
        return route('artifacts.viewer', [
            'task' => $this->task->id,
            'filename' => $artifact->filename,
        ]);
    }

    protected function postToSource(string $message): void
    {
        match ($this->task->source) {
            'slack' => $this->postToSlack($message),
            'linear' => $this->postToLinear($message),
            default => null,
        };
    }

    protected function postToSlack(string $message): void
    {
        $token = (string) config('yak.channels.slack.bot_token');

        if ($token === '' || ! $this->task->slack_channel) {
            return;
        }

        // The message arrives as common Markdown (`**bold**`,
        // `[label](url)`) so the Linear path renders correctly. Slack
        // uses mrkdwn (`*bold*`, `<url|label>`); convert before posting
        // or the link surfaces as raw markdown text in the thread.
        Http::withToken($token)
            ->post('https://slack.com/api/chat.postMessage', [
                'channel' => $this->task->slack_channel,
                'thread_ts' => $this->task->slack_thread_ts,
                'text' => SlackBlockFormatter::mrkdwn($message),
            ]);
    }

    protected function postToLinear(string $message): void
    {
        $sessionId = (string) $this->task->linear_agent_session_id;

        if ($sessionId === '') {
            return;
        }

        app(LinearNotificationDriver::class)
            ->postAgentActivity($sessionId, type: 'response', body: $message);
    }

    protected function moveLinearToDone(): void
    {
        $stateId = (string) config('yak.channels.linear.done_state_id');

        if ($stateId === '') {
            return;
        }

        app(LinearNotificationDriver::class)
            ->setIssueState($this->task, $stateId);
    }
}
