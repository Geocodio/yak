<?php

use App\Ai\Agents\TaskTitleAgent;
use App\Jobs\SendEmailAcknowledgmentJob;
use App\Mail\TaskStartedMail;
use App\Models\EmailMessage;
use App\Models\TaskAttachment;
use App\Models\User;
use App\Models\YakTask;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * @param  array<string, mixed>  $attributes
 * @param  array<string, mixed>  $email
 */
function emailTask(array $attributes = [], array $email = []): YakTask
{
    return YakTask::factory()->create([
        'source' => 'email',
        'repo' => 'geocodio',
        'mode' => 'research',
        'external_id' => 'EMAIL-20261109-1',
        'description' => "Figure out why the error consumption is so high\n\n---\n\nforwarded",
        'context' => json_encode(['email' => [
            'from' => 'mhansen@hey.com',
            'subject' => "Fwd: You've consumed 82% of your monthly pay-as-you-go budget for errors",
            'message_id' => 'forward-1@hey.com',
            'original_sender' => 'Sentry <noreply@md.getsentry.com>',
            'original_date' => 'Sun, Nov 9, 2026 at 8:31 PM',
            'instruction' => 'Figure out why the error consumption is so high',
            ...$email,
        ]]),
        ...$attributes,
    ]);
}

function startedMail(YakTask ...$tasks): TaskStartedMail
{
    /** @var array<string, mixed> $email */
    $email = json_decode((string) $tasks[0]->context, true)['email'];

    return new TaskStartedMail(collect($tasks), 'Investigate high Sentry error consumption', $email, 'ack-1@geocodio.yak.build');
}

beforeEach(function (): void {
    config()->set('yak.channels.email.inbound_address', 'yak@geocodio.yak.build');
    config()->set('yak.channels.email.reply_to', null);
});

it('prefers the stored title for the headline', function () {
    $task = YakTask::factory()->make(['title' => 'Investigate X', 'description' => "Long note\nmore"]);

    expect($task->headline())->toBe('Investigate X');
});

it('generates a title, saves it, and sends a threaded auto-reply', function () {
    Mail::fake();
    TaskTitleAgent::fake(['  Investigate high Sentry error consumption.  ']);
    $task = emailTask();

    (new SendEmailAcknowledgmentJob([$task->id]))->handle();

    expect($task->fresh()->title)->toBe('Investigate high Sentry error consumption');
    Mail::assertSent(TaskStartedMail::class, fn (TaskStartedMail $mail) => $mail->hasTo('mhansen@hey.com')
        && $mail->title === 'Investigate high Sentry error consumption');
    expect(EmailMessage::query()->where('direction', 'outbound')->where('yak_task_id', $task->id)->count())->toBe(1);
});

it('falls back to the cleaned subject when title generation fails', function (string $subject, string $expected) {
    Mail::fake();
    TaskTitleAgent::fake(fn () => throw new RuntimeException('model down'));
    $task = emailTask(email: ['subject' => $subject]);

    (new SendEmailAcknowledgmentJob([$task->id]))->handle();

    expect($task->fresh()->title)->toBe($expected);
})->with([
    'forward prefixes' => ['Fwd: FW: Re: Budget alert', 'Budget alert'],
    'only a prefix' => ['Fwd: ', 'Forwarded email'],
    'emoji' => ['🔥', '🔥'],
]);

it('does nothing for tasks without email context', function () {
    Mail::fake();
    $task = YakTask::factory()->create(['source' => 'email', 'context' => null]);

    (new SendEmailAcknowledgmentJob([$task->id]))->handle();

    Mail::assertNothingSent();
});

it('threads the reply under the forward and marks it as an auto-reply', function () {
    $mail = startedMail(emailTask());

    $mail->assertHasSubject("Re: Fwd: You've consumed 82% of your monthly pay-as-you-go budget for errors");
    $mail->assertHasReplyTo('no-reply@geocodio.yak.build');

    $headers = $mail->headers();
    expect($headers->messageId)->toBe('ack-1@geocodio.yak.build')
        ->and($headers->references)->toBe(['forward-1@hey.com'])
        ->and($headers->text)->toBe(['In-Reply-To' => '<forward-1@hey.com>', 'Auto-Submitted' => 'auto-replied']);
});

it('leaves out thread headers when the forward had no Message-ID', function () {
    $headers = startedMail(emailTask(email: ['message_id' => null]))->headers();

    expect($headers->references)->toBe([])
        ->and($headers->text)->toBe(['Auto-Submitted' => 'auto-replied']);
});

it('renders the started state with repo, mode, task link, instruction, and attachments', function () {
    Storage::fake('artifacts');
    $task = emailTask();
    TaskAttachment::storeContent('usage-chart.png', str_repeat('a', 2048), 'image/png', ['yak_task_id' => $task->id]);
    $mail = startedMail($task);

    $mail->assertSeeInHtml('Task started');
    $mail->assertSeeInHtml('Investigate high Sentry error consumption');
    $mail->assertSeeInHtml('researching this in');
    $mail->assertSeeInHtml('geocodio');
    $mail->assertSeeInHtml('Research');
    $mail->assertSeeInHtml('EMAIL-20261109-1');
    $mail->assertSeeInHtml(route('tasks.show', $task));
    $mail->assertSeeInHtml('Open task in Yak');
    $mail->assertSeeInHtml('Figure out why the error consumption is so high');
    $mail->assertSeeInHtml('noreply@md.getsentry.com', escape: true);
    $mail->assertSeeInHtml('usage-chart.png');
    $mail->assertSeeInHtml('2 KB');
    $mail->assertSeeInHtml("Replies to this email aren't read", escape: false);
    $mail->assertSeeInText('Open task in Yak: ' . route('tasks.show', $task));
    $mail->assertDontSeeInHtml('Needs one answer');
});

it('says it is working on a fix for fix-mode tasks', function () {
    startedMail(emailTask(['mode' => 'fix']))->assertSeeInHtml('working on a fix in');
});

it('renders the clarification state with one link per option carrying ?repo=', function () {
    $task = emailTask(['repo' => 'unknown', 'status' => 'awaiting_clarification', 'clarification_options' => ['geocodio', 'geocodio-website']]);
    $mail = startedMail($task);

    $mail->assertSeeInHtml('Needs one answer');
    $mail->assertSeeInHtml(route('tasks.show', ['task' => $task, 'repo' => 'geocodio']));
    $mail->assertSeeInHtml(route('tasks.show', ['task' => $task, 'repo' => 'geocodio-website']));
    $mail->assertSeeInHtml('confirm with one click');
    $mail->assertDontSeeInHtml('Task started');
});

it('renders one row per task for multi-repo forwards', function () {
    $first = emailTask(['external_id' => 'EMAIL-20261109-1-geocodio']);
    $second = emailTask(['repo' => 'geocodio-website', 'external_id' => 'EMAIL-20261109-1-geocodio-website']);
    $mail = startedMail($first, $second);

    $mail->assertSeeInHtml('2 repositories');
    $mail->assertSeeInHtml(route('tasks.show', $first));
    $mail->assertSeeInHtml(route('tasks.show', $second));
});

it('uses no coloured left border in the email', function () {
    expect(startedMail(emailTask())->render())->not->toContain('border-left');
});

it('records who it was sent to in the footer', function () {
    $user = User::factory()->create();
    startedMail(emailTask(['started_by_user_id' => $user->id]))
        ->assertSeeInHtml('you forwarded an email from mhansen@hey.com')
        ->assertSeeInHtml(route('profile.edit'));
});
