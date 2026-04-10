<?php

namespace App\Drivers;

use App\Contracts\NotificationDriver;
use App\Models\YakTask;
use Illuminate\Support\Facades\Http;

class SlackNotificationDriver implements NotificationDriver
{
    /**
     * Post a status update as a threaded reply in Slack.
     */
    public function postStatusUpdate(YakTask $task, string $message): void
    {
        $this->postThreadedReply($task, $message);
    }

    /**
     * Post the final result as a threaded reply in Slack.
     */
    public function postResult(YakTask $task, string $summary): void
    {
        $this->postThreadedReply($task, $summary);
    }

    /**
     * Post a threaded reply to the Slack channel associated with the task.
     */
    private function postThreadedReply(YakTask $task, string $message): void
    {
        $token = (string) config('yak.channels.slack.bot_token');

        if ($token === '' || ! $task->slack_channel || ! $task->slack_thread_ts) {
            return;
        }

        Http::withToken($token)
            ->post('https://slack.com/api/chat.postMessage', [
                'channel' => $task->slack_channel,
                'thread_ts' => $task->slack_thread_ts,
                'text' => $message,
            ]);
    }
}
