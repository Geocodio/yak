<?php

namespace App\Mail;

use App\Enums\TaskMode;
use App\Jobs\SendEmailAcknowledgmentJob;
use App\Models\TaskAttachment;
use App\Models\YakTask;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;
use Illuminate\Support\Str;

/**
 * The one acknowledgment Yak sends for a forwarded email: what it
 * understood, where it is working, and a link to continue in Yak. When the
 * repository is unclear it offers one link per candidate instead.
 */
class TaskStartedMail extends Mailable
{
    use Queueable;

    /**
     * @param  Collection<int, YakTask>  $tasks
     * @param  array{from?: string, subject?: string, message_id?: ?string, original_sender?: ?string, original_date?: ?string, instruction?: string}  $email
     */
    public function __construct(
        public Collection $tasks,
        public string $title,
        public array $email,
        public string $messageId,
    ) {}

    public function envelope(): Envelope
    {
        $replyTo = (string) config('yak.channels.email.reply_to') ?: 'no-reply@' . SendEmailAcknowledgmentJob::domain();

        return new Envelope(
            subject: 'Re: ' . ($this->email['subject'] ?? 'your email'),
            replyTo: [new Address($replyTo)],
        );
    }

    public function headers(): Headers
    {
        $forwardMessageId = $this->email['message_id'] ?? null;

        return new Headers(
            messageId: $this->messageId,
            references: $forwardMessageId !== null ? [$forwardMessageId] : [],
            text: [
                ...($forwardMessageId !== null ? ['In-Reply-To' => "<{$forwardMessageId}>"] : []),
                'Auto-Submitted' => 'auto-replied',
            ],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.task-started',
            text: 'mail.task-started-text',
            with: $this->viewData(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function viewData(): array
    {
        /** @var YakTask $first */
        $first = $this->tasks->first();
        $needsAnswer = $first->repo === 'unknown';
        $isResearch = $first->mode === TaskMode::Research;
        $instruction = (string) ($this->email['instruction'] ?? '');

        $sentence = match (true) {
            $needsAnswer => "I couldn't tell which repository this belongs to. Pick one and I'll start right away.",
            $this->tasks->count() > 1 => ($isResearch ? "I'm researching this in " : "I'm working on this in ") . $this->tasks->count() . ' repositories.',
            $isResearch => "I'm researching this in {$first->repo}.",
            default => "I'm working on a fix in {$first->repo}.",
        };

        return [
            'title' => $this->title,
            'needsAnswer' => $needsAnswer,
            'sentence' => $sentence,
            'preheader' => $needsAnswer
                ? 'Needs one answer: which repository?'
                : 'Task started in ' . $this->tasks->pluck('repo')->implode(', ') . ': ' . Str::limit(Str::before($instruction, "\n"), 90),
            'rows' => $this->tasks->map(fn (YakTask $task): array => [
                'url' => route('tasks.show', $task),
                'repo' => $task->repo,
                'mode' => $task->mode === TaskMode::Research ? 'Research' : 'Fix',
                'externalId' => $task->external_id,
            ])->values()->all(),
            'repoOptions' => collect($first->clarification_options ?? [])->map(fn (string $slug): array => [
                'slug' => $slug,
                'url' => route('tasks.show', ['task' => $first, 'repo' => $slug]),
            ])->all(),
            'instruction' => $instruction,
            'subject' => (string) ($this->email['subject'] ?? ''),
            'originalSender' => $this->email['original_sender'] ?? null,
            'originalDate' => $this->email['original_date'] ?? null,
            'from' => (string) ($this->email['from'] ?? ''),
            'attachments' => $first->attachments()->where('context', TaskAttachment::CONTEXT_REQUEST)->orderBy('id')->get()
                ->map(fn (TaskAttachment $attachment): array => [
                    'name' => $attachment->original_name,
                    'size' => Number::fileSize($attachment->size_bytes),
                ])->all(),
            'workingStep' => $isResearch ? "Researching in {$first->repo}" : "Working on a fix in {$first->repo}",
            'resultStep' => $isResearch ? 'Findings posted in Yak for you to review' : 'A PR opened for you to review',
            'settingsUrl' => route('profile.edit'),
        ];
    }
}
