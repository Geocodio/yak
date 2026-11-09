<?php

namespace App\Jobs;

use App\Ai\Agents\TaskTitleAgent;
use App\Mail\TaskStartedMail;
use App\Models\EmailMessage;
use App\Models\YakTask;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Titles the tasks a forwarded email created and sends the one
 * acknowledgment the email channel ever sends, threaded under the forward.
 * The acknowledgment's Message-ID is recorded so a reply to it is
 * recognised as a thread follow-up and never starts a task.
 */
class SendEmailAcknowledgmentJob implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<int>  $taskIds
     */
    public function __construct(public array $taskIds) {}

    public function handle(): void
    {
        $tasks = YakTask::query()->whereIn('id', $this->taskIds)->orderBy('id')->get();

        /** @var array{from?: string, subject?: string, message_id?: ?string, instruction?: string}|null $email */
        $email = json_decode((string) $tasks->first()?->context, true)['email'] ?? null;

        if (! is_array($email) || ! is_string($email['from'] ?? null)) {
            return;
        }

        $title = $this->title((string) ($email['instruction'] ?? ''), (string) ($email['subject'] ?? ''));
        $tasks->each->update(['title' => $title]);

        $messageId = Str::uuid() . '@' . self::domain();

        Mail::to($email['from'])->send(new TaskStartedMail($tasks, $title, $email, $messageId));

        EmailMessage::create(['message_id' => $messageId, 'yak_task_id' => $tasks->first()?->id, 'direction' => EmailMessage::DIRECTION_OUTBOUND]);
    }

    private function title(string $instruction, string $subject): string
    {
        try {
            $title = (string) (new TaskTitleAgent)->prompt("Note:\n{$instruction}\n\nForwarded subject:\n{$subject}");
            $title = trim(rtrim(trim($title, " \t\n\r\"'`"), '.'));

            if ($title !== '') {
                return Str::limit($title, 200, '');
            }
        } catch (\Throwable $exception) {
            Log::channel('yak')->warning('Task title generation failed', ['error' => $exception->getMessage()]);
        }

        return self::fallbackTitle($subject);
    }

    /**
     * The forwarded subject without its Fwd:/FW:/Re: prefixes.
     */
    public static function fallbackTitle(string $subject): string
    {
        $title = trim((string) preg_replace('/^\s*(?:(?:fwd?|fw|re)\s*:\s*)+/i', '', $subject));

        return $title === '' ? 'Forwarded email' : Str::limit($title, 200, '');
    }

    /**
     * The domain for Message-IDs and the default no-reply address: the
     * inbound address's domain, else the app's host.
     */
    public static function domain(): string
    {
        $inboundAddress = (string) config('yak.channels.email.inbound_address');

        return str_contains($inboundAddress, '@')
            ? Str::afterLast($inboundAddress, '@')
            : (string) (parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost');
    }
}
