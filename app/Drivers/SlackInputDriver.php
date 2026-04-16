<?php

namespace App\Drivers;

use App\Contracts\InputDriver;
use App\DataTransferObjects\TaskDescription;
use App\Enums\TaskMode;
use App\Models\YakTask;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SlackInputDriver implements InputDriver
{
    /**
     * Parse a Slack app_mention event into a normalized task description.
     */
    public function parse(Request $request): TaskDescription
    {
        /** @var array{text?: string, channel?: string, thread_ts?: string, ts?: string, user?: string} $event */
        $event = $request->input('event', []);
        $rawText = $event['text'] ?? '';
        $text = $this->stripMention($rawText);

        $repository = $this->detectRepo($text);
        $mode = $this->detectMode($text);
        $description = $this->cleanDescription($text);

        return new TaskDescription(
            title: Str::limit($description, 100),
            body: $description,
            channel: 'slack',
            externalId: $this->generateExternalId(),
            repository: $repository,
            metadata: [
                'mode' => $mode->value,
                'slack_channel' => $event['channel'] ?? '',
                'slack_thread_ts' => $event['thread_ts'] ?? $event['ts'] ?? '',
                'slack_user_id' => $event['user'] ?? '',
                // The ts of the @yak mention itself — always the event's
                // `ts` regardless of whether we're in a thread. This is
                // what reactions get applied to.
                'slack_message_ts' => $event['ts'] ?? '',
            ],
        );
    }

    /**
     * Strip the @yak bot mention (Slack formats as <@BOT_ID>).
     */
    private function stripMention(string $text): string
    {
        return trim((string) preg_replace('/<@[A-Z0-9_]+>/', '', $text));
    }

    /**
     * Detect explicit repo from "in {repo}:" syntax.
     */
    private function detectRepo(string $text): ?string
    {
        if (preg_match('/\bin\s+([\w\-\/]+):/i', $text, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Detect task mode from "research:" prefix.
     */
    private function detectMode(string $text): TaskMode
    {
        if (preg_match('/\bresearch:/i', $text)) {
            return TaskMode::Research;
        }

        return TaskMode::Fix;
    }

    /**
     * Clean the description by removing directives (repo and mode prefixes).
     */
    private function cleanDescription(string $text): string
    {
        $text = (string) preg_replace('/\bin\s+[\w\-\/]+:\s*/i', '', $text);
        $text = (string) preg_replace('/\bresearch:\s*/i', '', $text);

        return trim($text);
    }

    /**
     * Generate external_id as SLACK-{YYYYMMDD}-{sequence}.
     */
    private function generateExternalId(): string
    {
        $date = now()->format('Ymd');
        $count = YakTask::where('source', 'slack')
            ->where('external_id', 'like', "SLACK-{$date}-%")
            ->count();

        return sprintf('SLACK-%s-%d', $date, $count + 1);
    }
}
