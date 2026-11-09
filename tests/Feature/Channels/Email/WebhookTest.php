<?php

use App\Jobs\ResearchYakJob;
use App\Jobs\RunYakJob;
use App\Jobs\SendEmailAcknowledgmentJob;
use App\Models\EmailMessage;
use App\Models\Observation;
use App\Models\Repository;
use App\Models\TaskAttachment;
use App\Models\User;
use App\Models\UserEmailAddress;
use App\Models\YakTask;
use App\Providers\ChannelServiceProvider;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    config()->set('yak.channels.email', [
        'webhook_password' => 'secret',
        'inbound_address' => 'yak@geocodio.yak.build',
        'require_dkim' => true,
    ]);
    config()->set('ai.providers.anthropic.key', '');
    config()->set('yak.intent_classifier.enabled', false);
    enableEmailChannelRoutes();

    Mail::fake();
    Queue::fake();
    Storage::fake('artifacts');

    $this->user = User::factory()->create(['email' => 'mathias@geocod.io', 'name' => 'Mathias Hansen']);
    UserEmailAddress::factory()->confirmed()->for($this->user)->create(['email' => 'mhansen@hey.com']);
    Repository::factory()->create(['slug' => 'geocodio', 'is_active' => true]);
});

function enableEmailChannelRoutes(): void
{
    (new ChannelServiceProvider(app()))->boot();
    app('router')->getRoutes()->refreshNameLookups();
}

/**
 * @param  array<string, mixed>  $overrides
 * @param  array<string, string>  $headers
 */
function postEmail(array $overrides = [], array $headers = [], string $password = 'secret'): TestResponse
{
    return test()->withBasicAuth('yak', $password)->postJson('/webhooks/email', postmarkPayload($overrides, $headers));
}

function expectDropped(TestResponse $response, string $reason): void
{
    $response->assertOk()->assertJson(['ok' => true, 'skipped' => $reason]);
    expect(YakTask::query()->count())->toBe(0);
    Mail::assertNothingOutgoing();
    Queue::assertNotPushed(SendEmailAcknowledgmentJob::class);
}

it('rejects a wrong webhook password with 403', function () {
    postEmail(password: 'wrong')->assertForbidden();
    test()->postJson('/webhooks/email', postmarkPayload())->assertForbidden();

    expect(YakTask::query()->count())->toBe(0);
});

it('drops mail without DKIM_VALID_AU', function () {
    expectDropped(postEmail(headers: ['X-Spam-Tests' => 'DKIM_SIGNED,DKIM_VALID,SPF_PASS']), 'dkim_failed');
});

it('drops mail with no X-Spam-Tests header', function () {
    $payload = postmarkPayload();
    $payload['Headers'] = [['Name' => 'Message-ID', 'Value' => '<a@hey.com>']];

    expectDropped(test()->withBasicAuth('yak', 'secret')->postJson('/webhooks/email', $payload), 'dkim_failed');
});

it('accepts unsigned mail when the DKIM check is turned off', function () {
    config()->set('yak.channels.email.require_dkim', false);

    postEmail(headers: ['X-Spam-Tests' => ''])->assertCreated();
});

it('drops unknown senders and unconfirmed addresses', function (string $sender) {
    UserEmailAddress::factory()->unconfirmed()->for($this->user)->create(['email' => 'pending@hey.com']);

    expectDropped(postEmail(['FromFull' => ['Email' => $sender]]), 'unknown_sender');
})->with(['stranger@hey.com', 'pending@hey.com']);

it('drops auto-submitted and bulk mail', function (array $headers) {
    expectDropped(postEmail(headers: $headers), 'auto_submitted');
})->with([[['Auto-Submitted' => 'auto-replied']], [['Precedence' => 'bulk']]]);

it('drops a repeat Message-ID', function () {
    postEmail()->assertCreated();
    $second = postEmail();

    $second->assertOk()->assertJson(['skipped' => 'duplicate']);
    expect(YakTask::query()->count())->toBe(1);
});

it('drops a reply in a thread that already created a task', function () {
    postEmail()->assertCreated();

    postEmail(headers: ['Message-ID' => '<reply-1@hey.com>', 'References' => '<forward-1@hey.com>'])
        ->assertOk()->assertJson(['skipped' => 'thread_follow_up']);

    expect(YakTask::query()->count())->toBe(1);
});

it('drops a reply to a Yak auto-reply', function () {
    EmailMessage::query()->create(['message_id' => 'ack-1@geocodio.yak.build', 'direction' => 'outbound']);

    expectDropped(postEmail(headers: ['Message-ID' => '<reply-1@hey.com>', 'In-Reply-To' => '<ack-1@geocodio.yak.build>']), 'thread_follow_up');
});

it('drops the 31st task in an hour for one user', function () {
    foreach (range(1, 30) as $number) {
        postEmail(headers: ['Message-ID' => "<forward-{$number}@hey.com>"])->assertCreated();
    }

    postEmail(headers: ['Message-ID' => '<forward-31@hey.com>'])->assertOk()->assertJson(['skipped' => 'rate_limited']);
    expect(YakTask::query()->count())->toBe(30);
});

it('counts every task a multi-repo forward creates against the hourly limit', function () {
    Repository::factory()->create(['slug' => 'geocodio-website', 'is_active' => true]);

    foreach (range(1, 15) as $number) {
        postEmail(['TextBody' => forwardedEmailBody('gmail', 'Check geocodio and geocodio-website')], ['Message-ID' => "<multi-{$number}@hey.com>"])->assertCreated();
    }

    postEmail(headers: ['Message-ID' => '<one-more@hey.com>'])->assertOk()->assertJson(['skipped' => 'rate_limited']);
    expect(YakTask::query()->count())->toBe(30);
});

it('answers 200 for malformed payloads', function (array $payload) {
    test()->withBasicAuth('yak', 'secret')->postJson('/webhooks/email', $payload)
        ->assertOk()->assertJson(['skipped' => 'malformed']);
})->with([
    'empty' => [[]],
    'sender not an object' => [['FromFull' => 'x']],
]);

it('answers 200 when optional fields are malformed', function () {
    test()->withBasicAuth('yak', 'secret')
        ->postJson('/webhooks/email', ['FromFull' => ['Email' => 'mhansen@hey.com'], 'Headers' => 'nope', 'Attachments' => 'nope'])
        ->assertOk()->assertJson(['skipped' => 'wrong_recipient']);
});

it('records a declined observation for each drop', function () {
    postEmail(['FromFull' => ['Email' => 'stranger@hey.com']]);

    expect(Observation::query()->where('kind', 'email.unknown_sender')->exists())->toBeTrue();
});

it('creates a task attributed to the sender with email context', function () {
    $response = postEmail()->assertCreated();

    $task = YakTask::query()->sole();
    $context = json_decode((string) $task->context, true);

    expect($response->json('task_ids'))->toBe([$task->id])
        ->and($task->source)->toBe('email')
        ->and($task->repo)->toBe('geocodio')
        ->and($task->external_id)->toBe('EMAIL-' . now()->format('Ymd') . '-1')
        ->and($task->started_by_user_id)->toBe($this->user->id)
        ->and($task->author_name)->toBe('Mathias Hansen')
        ->and($task->description)->toStartWith("Figure out why the error consumption is so high, and whether it's in the geocodio repo")
        ->and($task->description)->toContain('consumed 82%')
        ->and($context['email'])->toMatchArray([
            'from' => 'mhansen@hey.com',
            'subject' => "Fwd: You've consumed 82% of your monthly pay-as-you-go budget for errors",
            'message_id' => 'forward-1@hey.com',
            'original_sender' => 'Sentry <noreply@md.getsentry.com>',
        ])
        ->and(EmailMessage::query()->where('message_id', 'forward-1@hey.com')->value('yak_task_id'))->toBe($task->id);
});

it('dispatches the research job when the note asks for research', function () {
    postEmail(['TextBody' => forwardedEmailBody('gmail', 'research: why are errors so high?')])->assertCreated();

    expect(YakTask::query()->sole()->mode->value)->toBe('research');
    Queue::assertPushed(ResearchYakJob::class);
});

it('dispatches the fix job otherwise', function () {
    postEmail(['TextBody' => forwardedEmailBody('gmail', 'in geocodio: fix the error flood')])->assertCreated();

    Queue::assertPushed(RunYakJob::class);
});

it('stores attachments, skips images under 10 KB, and keeps safe unique names', function () {
    $big = base64_encode(str_repeat('a', 12 * 1024));

    postEmail(['Attachments' => [
        ['Name' => 'usage-chart.png', 'Content' => $big, 'ContentType' => 'image/png'],
        ['Name' => 'usage-chart.png', 'Content' => $big, 'ContentType' => 'image/png'],
        ['Name' => '../../etc/passwd', 'Content' => base64_encode('root:x:0:0'), 'ContentType' => 'text/plain'],
        ['Name' => 'logo.png', 'Content' => base64_encode(str_repeat('l', 2048)), 'ContentType' => 'image/png', 'ContentID' => 'logo'],
    ]])->assertCreated();

    $attachments = TaskAttachment::query()->orderBy('id')->get();

    expect($attachments->pluck('original_name')->all())->toBe(['usage-chart.png', 'usage-chart.png', 'passwd'])
        ->and($attachments->pluck('disk_path')->unique())->toHaveCount(3)
        ->and($attachments->every(fn (TaskAttachment $attachment) => ! str_contains($attachment->disk_path, '..')))->toBeTrue()
        ->and($attachments->first()->uploaded_by_user_id)->toBe($this->user->id)
        ->and($attachments->first()->size_bytes)->toBe(12 * 1024);
    Storage::disk('artifacts')->assertExists($attachments->first()->disk_path);
});

it('defaults the attachment size limit to 25 MB', function () {
    expect((require config_path('yak.php'))['channels']['email']['max_attachment_bytes'])->toBe(25 * 1024 * 1024);
});

it('skips attachments over the size limit and keeps at most 20', function () {
    config()->set('yak.channels.email.max_attachment_bytes', 1024);
    $small = ['Name' => 'log.txt', 'Content' => base64_encode('line'), 'ContentType' => 'text/plain'];
    $huge = ['Name' => 'dump.bin', 'Content' => base64_encode(str_repeat('x', 1025)), 'ContentType' => 'application/octet-stream'];

    postEmail(['Attachments' => [$huge, ...array_fill(0, 25, $small)]])->assertCreated();

    expect(TaskAttachment::query()->count())->toBe(20)
        ->and(TaskAttachment::query()->where('original_name', 'dump.bin')->exists())->toBeFalse();
});

it('creates an awaiting_clarification task when the router is unsure', function () {
    Repository::factory()->create(['slug' => 'geocodio-website', 'is_active' => true]);

    postEmail(['TextBody' => forwardedEmailBody('gmail', 'Why is this so high?')])->assertCreated();

    $task = YakTask::query()->sole();
    expect($task->repo)->toBe('unknown')
        ->and($task->status->value)->toBe('awaiting_clarification')
        ->and($task->clarification_options)->toEqualCanonicalizing(['geocodio', 'geocodio-website']);
    Queue::assertNotPushed(RunYakJob::class);
    Queue::assertNotPushed(ResearchYakJob::class);
});

it('creates one task per repo for multi-repo notes', function () {
    Repository::factory()->create(['slug' => 'geocodio-website', 'is_active' => true]);

    $response = postEmail(['TextBody' => forwardedEmailBody('gmail', 'Check this in geocodio and geocodio-website')])->assertCreated();

    expect(YakTask::query()->pluck('repo')->sort()->values()->all())->toBe(['geocodio', 'geocodio-website'])
        ->and($response->json('task_ids'))->toHaveCount(2);
});

it('drops a From header that lists more than one address', function () {
    expectDropped(postEmail(['From' => 'mhansen@hey.com, attacker@evil.test']), 'malformed');
});

it('drops mail that was not addressed to the inbound address', function () {
    expectDropped(postEmail([
        'OriginalRecipient' => 'no-reply@geocodio.yak.build',
        'ToFull' => [['Email' => 'no-reply@geocodio.yak.build']],
    ]), 'wrong_recipient');
});

it('accepts Yak in CC or as the envelope recipient of a BCC, ignoring case', function (array $overrides) {
    postEmail(['ToFull' => [['Email' => 'someone@hey.com']], 'OriginalRecipient' => '', ...$overrides])->assertCreated();
})->with([
    'cc' => [['CcFull' => [['Email' => 'YAK@geocodio.yak.build']]]],
    'bcc envelope' => [['OriginalRecipient' => 'yak@geocodio.yak.build']],
]);

it('reports the created task in the shape webhook telemetry reads', function () {
    $response = postEmail()->assertCreated();

    expect($response->json('handled'))->toBe('task_created')
        ->and($response->json('task_id'))->toBe(YakTask::query()->sole()->id);
});

it('does not create a second task when Postmark retries after a failure mid-request', function () {
    $artifacts = Mockery::mock(Filesystem::class);
    $artifacts->shouldReceive('put')->andThrow(new RuntimeException('disk full'));
    Storage::set('artifacts', $artifacts);
    $attachment = ['Name' => 'log.txt', 'Content' => base64_encode('line'), 'ContentType' => 'text/plain'];

    $this->withoutExceptionHandling();
    expect(fn () => postEmail(['Attachments' => [$attachment]]))->toThrow(RuntimeException::class);

    Storage::fake('artifacts');
    postEmail(['Attachments' => [$attachment]])->assertOk()->assertJson(['skipped' => 'duplicate']);
    expect(YakTask::query()->count())->toBe(1);
});

it('dispatches the acknowledgment job with every task id', function () {
    $taskIds = postEmail()->assertCreated()->json('task_ids');

    Queue::assertPushed(SendEmailAcknowledgmentJob::class, fn (SendEmailAcknowledgmentJob $job) => $job->taskIds === $taskIds);
});
