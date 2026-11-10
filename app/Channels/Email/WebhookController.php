<?php

namespace App\Channels\Email;

use App\DataTransferObjects\TaskDescription;
use App\Enums\TaskMode;
use App\Http\Concerns\RecordsWebhookTelemetry;
use App\Http\Controllers\Controller;
use App\Jobs\ResearchYakJob;
use App\Jobs\RunYakJob;
use App\Jobs\SendEmailAcknowledgmentJob;
use App\Models\EmailMessage;
use App\Models\TaskAttachment;
use App\Models\User;
use App\Models\YakTask;
use App\Services\AgentJobDispatcher;
use App\Services\ObservationRecorder;
use App\Services\RepoDetector;
use App\Services\ResponsiblePersonResolver;
use App\Services\TaskIntentClassification;
use App\Services\TaskLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Turns a forwarded email into a task. In order: the request must carry
 * the webhook's basic auth credentials; it must be addressed to Yak; the
 * raw message must carry a valid DKIM signature from its From domain,
 * verified by Yak itself; the sender must be a Yak user's sign-in or
 * confirmed address; and the message must not be a duplicate, a reply in a
 * thread that already made a task, or over the sender's hourly limit.
 *
 * Every drop after authentication answers 200, so Postmark does not retry
 * it, and never sends email, because the sender may be spoofed.
 */
class WebhookController extends Controller
{
    use RecordsWebhookTelemetry;

    public const int TASKS_PER_USER_PER_HOUR = 30;

    public const int MAX_ATTACHMENTS = 20;

    /** Images this small are signature logos and tracking pixels. */
    public const int MIN_IMAGE_BYTES = 10 * 1024;

    public function __construct(private readonly InputDriver $inputDriver) {}

    public function __invoke(Request $request): JsonResponse
    {
        $password = (string) config('yak.channels.email.webhook_password');

        abort_unless(
            $password !== '' && $request->getUser() === 'yak' && hash_equals($password, (string) $request->getPassword()),
            403,
        );

        return $this->recordWebhook('email', 'inbound', fn (): JsonResponse => $this->route($request));
    }

    private function route(Request $request): JsonResponse
    {
        $email = $this->inputDriver->parsePayload($request->all());

        if ($email === null) {
            return $this->dropped('malformed', null, 'An email arrived without a readable sender');
        }

        $describe = "An email from {$email->fromEmail} (\"" . Str::limit($email->subject, 80) . '")';

        // The inbound domain routes every local part here, including the
        // no-reply address acknowledgments use. Only mail to Yak's own
        // address counts, which also stops loops with auto-responders.
        $inboundAddress = mb_strtolower(trim((string) config('yak.channels.email.inbound_address')));

        if (! in_array($inboundAddress, $email->recipients, true)) {
            return $this->dropped('wrong_recipient', $email, "{$describe} was not addressed to {$inboundAddress}");
        }

        if ($email->autoSubmitted) {
            return $this->dropped('auto_submitted', $email, "{$describe} was an automatic or bulk message");
        }

        // Look the sender up before verifying, so only mail that claims a
        // confirmed address ever reaches the DKIM verifier.
        $user = User::findBySenderEmail($email->fromEmail);

        if ($user === null) {
            return $this->dropped('unknown_sender', $email, "{$describe} came from an address that is not a confirmed Yak sender");
        }

        if ((bool) config('yak.channels.email.require_dkim', true) && ! app(DkimVerifier::class)->verify($email->rawEmail, $email->fromEmail, $inboundAddress)) {
            app(VerificationFailureNotifier::class)->notify($user, $email);

            return $this->dropped('dkim_failed', $email, "{$describe} failed sender verification (no valid DKIM signature from the sender's domain on a message addressed to Yak)");
        }

        if ($email->messageId !== null && EmailMessage::seen([$email->messageId])) {
            return $this->dropped('duplicate', $email, "{$describe} was already turned into a task");
        }

        if (EmailMessage::seen($email->threadMessageIds)) {
            return $this->dropped('thread_follow_up', $email, "{$describe} replied in a thread that already started a task");
        }

        $rateLimitKey = "email-tasks:{$user->id}";

        if (RateLimiter::tooManyAttempts($rateLimitKey, self::TASKS_PER_USER_PER_HOUR)) {
            return $this->dropped('rate_limited', $email, "{$describe} went over the limit of " . self::TASKS_PER_USER_PER_HOUR . ' email tasks per hour');
        }

        $tasks = $this->createTasks($email, $user);

        // One slot per task, so a note naming several repositories counts
        // as several tasks. The forward that crosses the limit still
        // completes; the next one is dropped.
        foreach ($tasks as $task) {
            RateLimiter::hit($rateLimitKey, 3600);
        }

        SendEmailAcknowledgmentJob::dispatch(array_map(fn (YakTask $task): int => $task->id, $tasks));

        return response()->json([
            'ok' => true,
            'handled' => 'task_created',
            'task_id' => $tasks[0]->id,
            'task_ids' => array_map(fn (YakTask $task): int => $task->id, $tasks),
        ], 201);
    }

    /**
     * @return list<YakTask>
     */
    private function createTasks(InboundEmail $email, User $user): array
    {
        $description = $this->inputDriver->describe($email);

        /** @var string $instruction */
        $instruction = $description->metadata['instruction'];
        $mode = $description->metadata['research_requested'] === true
            ? TaskMode::Research
            : app(TaskIntentClassification::class)->classify($instruction);

        // Route on the note alone: a long forwarded email can name many
        // repositories and would mislead the router.
        $detection = (new RepoDetector)->detect(new TaskDescription(
            title: $description->title,
            body: $instruction,
            channel: 'email',
            externalId: $description->externalId,
            repository: $description->repository,
        ));

        $context = json_encode(['email' => [
            'from' => $email->fromEmail,
            'subject' => $email->subject,
            'message_id' => $email->messageId,
            'original_sender' => $email->originalSender,
            'original_date' => $email->originalDate,
            'instruction' => $instruction,
        ]]);

        $base = [
            'source' => 'email',
            'description' => $description->body,
            'mode' => $mode->value,
            'context' => $context,
            'author_name' => $user->name,
            'started_by_user_id' => $user->id,
        ];

        if ($detection->needsClarification) {
            $tasks = [YakTask::create([
                ...$base,
                ...$this->responsible($user, 'unknown'),
                'repo' => 'unknown',
                'external_id' => $description->externalId,
                'status' => 'awaiting_clarification',
                'clarification_options' => $detection->options->pluck('slug')->values()->all(),
                ...YakTask::clarificationDeadlines(),
            ])];
        } else {
            $slugs = $detection->resolved
                ? $detection->repositories->pluck('slug')->values()->all()
                : [$description->repository ?? 'unknown'];

            $tasks = array_values(array_map(fn (string $slug): YakTask => YakTask::create([
                ...$base,
                ...$this->responsible($user, $slug),
                'repo' => $slug,
                'external_id' => count($slugs) > 1 ? "{$description->externalId}-{$slug}" : $description->externalId,
            ]), $slugs));
        }

        // Record the Message-ID before anything that can fail, so a Postmark
        // retry after an error is a duplicate rather than a second task.
        if ($email->messageId !== null) {
            EmailMessage::create(['message_id' => $email->messageId, 'yak_task_id' => $tasks[0]->id, 'direction' => EmailMessage::DIRECTION_INBOUND]);
        }

        foreach ($tasks as $task) {
            $this->storeAttachments($email, $task, $user);

            TaskLogger::info($task, 'Task created', ['source' => 'email', 'repo' => $task->repo, 'from' => $email->fromEmail]);

            if ($task->repo !== 'unknown') {
                app(AgentJobDispatcher::class)->dispatch($task, $mode === TaskMode::Research ? ResearchYakJob::class : RunYakJob::class);
            }

            ObservationRecorder::acted(
                source: 'email',
                kind: 'email.task_created',
                summary: "{$user->name} forwarded \"" . Str::limit($email->subject, 80) . "\"; opened task #{$task->id}.",
                repo: $task->repo,
                subject: $email->messageId,
                task: $task,
            );
        }

        return $tasks;
    }

    /**
     * @return array{responsible_name: ?string, responsible_user_id: ?int}
     */
    private function responsible(User $user, string $slug): array
    {
        $resolver = app(ResponsiblePersonResolver::class);

        return [
            'responsible_name' => $resolver->resolve(null, $user->name, $slug),
            'responsible_user_id' => $resolver->resolveUser(null, $user, $slug)?->id,
        ];
    }

    private function storeAttachments(InboundEmail $email, YakTask $task, User $user): void
    {
        $maxBytes = (int) config('yak.channels.email.max_attachment_bytes', 25 * 1024 * 1024);
        $stored = 0;

        foreach ($email->attachments as $attachment) {
            $isImage = str_starts_with(strtolower($attachment['contentType']), 'image/');

            if ($isImage && $attachment['size'] < self::MIN_IMAGE_BYTES) {
                continue;
            }

            if ($attachment['size'] > $maxBytes || $stored >= self::MAX_ATTACHMENTS) {
                Log::channel('yak')->info('Email attachment skipped', [
                    'task_id' => $task->id,
                    'name' => $attachment['name'],
                    'size' => $attachment['size'],
                    'reason' => $attachment['size'] > $maxBytes ? 'too_large' : 'too_many',
                ]);

                continue;
            }

            TaskAttachment::storeContent($attachment['name'], (string) base64_decode($attachment['content']), $attachment['contentType'], [
                'yak_task_id' => $task->id,
                'uploaded_by_user_id' => $user->id,
            ]);
            $stored++;
        }
    }

    /**
     * Log and record why a message was ignored. Never replies by email.
     */
    private function dropped(string $reason, ?InboundEmail $email, string $summary): JsonResponse
    {
        Log::channel('yak')->info('Email dropped', [
            'reason' => $reason,
            'from' => $email?->fromEmail,
            'message_id' => $email?->messageId,
        ]);

        ObservationRecorder::declined(
            source: 'email',
            kind: "email.{$reason}",
            summary: "{$summary}, so Yak took no action.",
            subject: $email->messageId ?? $email?->fromEmail,
        );

        return response()->json(['ok' => true, 'skipped' => $reason]);
    }
}
