<?php

use App\DataTransferObjects\ClarificationQuestion;
use App\Enums\TaskStatus;
use App\Enums\ThreadShareMode;
use App\Jobs\RunFollowUpJob;
use App\Jobs\RunYakJob;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Threads\ThreadSharing;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ContractFixture;

/** @return array{0: Thread, 1: User, 2: YakTask} */
function threadWithOpenQuestions(): array
{
    $user = User::factory()->create(['name' => 'Test person']);
    $thread = Thread::factory()->create(['title' => 'Retry failed uploads', 'repo' => 'example/api', 'created_by_user_id' => $user->id, 'owner_user_id' => $user->id]);
    $task = YakTask::factory()->create(['thread_id' => $thread->id, 'status' => TaskStatus::AwaitingClarification, 'started_by_user_id' => $user->id, 'clarification_expires_at' => now()->addDay()]);
    $task->recordClarificationRound([
        ClarificationQuestion::fromArray([
            'id' => 'on_failure', 'header' => 'On failure', 'question' => 'When all retries fail, what should happen to the row?',
            'options' => [['label' => 'Mark the row as failed'], ['label' => 'Fail the whole batch']], 'multi_select' => false,
        ]),
        ClarificationQuestion::fromArray([
            'id' => 'notify', 'header' => 'Notify', 'question' => 'Who should hear about a failed batch?',
            'options' => [['label' => 'The uploader'], ['label' => 'The team channel']], 'multi_select' => true,
        ]),
    ], 'I need two decisions.');

    return [$thread, $user, $task];
}

function answersPath(Thread $thread): string
{
    return appThreadPath($thread, '/clarification-answers');
}

it('answers the open question of the thread', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 12:00:00'));
    Queue::fake();
    [$thread, $user, $task] = threadWithOpenQuestions();
    $headers = phoneHeadersFor($user);

    $response = $this->postJson(answersPath($thread), ['answers' => [
        'on_failure' => ['choices' => ['Mark the row as failed'], 'other' => null],
        'notify' => ['choices' => ['The uploader', 'The team channel'], 'other' => null],
    ], 'note' => null], $headers)->assertOk()->assertExactJson(['status' => 'sent']);

    expect($task->fresh()->status)->toBe(TaskStatus::Pending);
    Queue::assertPushed(RunYakJob::class, 1);
    Queue::assertNotPushed(RunFollowUpJob::class);
    $this->getJson(appThreadPath($thread), $headers)->assertOk()->assertJsonPath('question', null);
    ContractFixture::assertMatches('app', 'clarification-answers', $response->json());
});

it('accepts a free-text answer', function () {
    Queue::fake();
    [$thread, $user] = threadWithOpenQuestions();

    $this->postJson(answersPath($thread), ['answers' => ['on_failure' => ['choices' => [], 'other' => 'Retry once more, then mark it failed']]], phoneHeadersFor($user))
        ->assertOk()->assertJson(['status' => 'sent']);
});

it('refuses answers that do not match the questions', function (array $answers, string $field) {
    Queue::fake();
    [$thread, $user] = threadWithOpenQuestions();

    $this->postJson(answersPath($thread), ['answers' => $answers], phoneHeadersFor($user))
        ->assertStatus(422)->assertJsonStructure(['details' => ['errors' => [$field]]]);
    Queue::assertNothingPushed();
})->with([
    'unknown question' => [['nope' => ['choices' => ['x'], 'other' => null]], 'answers'],
    'unknown choice' => [['on_failure' => ['choices' => ['Ignore it'], 'other' => null]], 'answers.on_failure'],
    'two choices on a single select' => [['on_failure' => ['choices' => ['Mark the row as failed', 'Fail the whole batch'], 'other' => null]], 'answers.on_failure'],
    'nothing answered' => [['on_failure' => ['choices' => [], 'other' => null]], 'answers'],
]);

it('enforces the limits', function (array $body, string $field) {
    Queue::fake();
    [$thread, $user] = threadWithOpenQuestions();

    $this->postJson(answersPath($thread), $body, phoneHeadersFor($user))
        ->assertStatus(422)->assertJsonStructure(['details' => ['errors' => [$field]]]);
})->with([
    'eleven answers' => [['answers' => array_fill_keys(range(1, 11), ['choices' => [], 'other' => 'x'])], 'answers'],
    'five choices' => [['answers' => ['notify' => ['choices' => ['a', 'b', 'c', 'd', 'e'], 'other' => null]]], 'answers.notify.choices'],
    'long choice' => [['answers' => ['notify' => ['choices' => [str_repeat('a', 501)], 'other' => null]]], 'answers.notify.choices.0'],
    'long note' => [['answers' => ['notify' => ['choices' => ['The uploader'], 'other' => null]], 'note' => str_repeat('a', 2001)], 'note'],
]);

it('answers question_closed when the round was answered elsewhere', function () {
    Queue::fake();
    [$thread, $user, $task] = threadWithOpenQuestions();
    $this->actingAs($user)->post(route('tasks.clarification-answers.store', $task), ['answers' => ['on_failure' => ['choices' => ['Fail the whole batch'], 'other' => null]]])->assertRedirect();
    $this->app['auth']->forgetGuards();

    $headers = phoneHeadersFor($user);
    $this->postJson(answersPath($thread), ['answers' => ['on_failure' => ['choices' => ['Mark the row as failed'], 'other' => null]]], $headers)
        ->assertStatus(409)->assertJsonPath('error', 'question_closed')
        ->assertJsonPath('message', 'These questions were already answered or have expired. Reload the thread to see where it stands.');

    Queue::assertPushed(RunYakJob::class, 1);
    Queue::assertNotPushed(RunFollowUpJob::class);
    $this->getJson(appThreadPath($thread), $headers)->assertOk()->assertJsonPath('question', null);
});

it('answers question_closed for a thread with no tasks', function () {
    $user = User::factory()->create();
    $thread = Thread::factory()->create(['created_by_user_id' => $user->id, 'owner_user_id' => $user->id]);

    $this->postJson(answersPath($thread), ['answers' => ['on_failure' => ['choices' => ['x'], 'other' => null]]], phoneHeadersFor($user))
        ->assertStatus(409)->assertJsonPath('error', 'question_closed');
});

it('lets a share recipient read but not answer', function () {
    Queue::fake();
    [$thread, $owner] = threadWithOpenQuestions();
    $recipient = User::factory()->create();
    app(ThreadSharing::class)->apply($thread, $owner, ThreadShareMode::People, [$recipient->id]);

    $this->postJson(answersPath($thread), ['answers' => ['on_failure' => ['choices' => ['Fail the whole batch'], 'other' => null]]], phoneHeadersFor($recipient))
        ->assertForbidden()->assertJsonPath('error', 'forbidden');
    Queue::assertNothingPushed();
});

it('answers a hidden thread like a missing one', function () {
    $hidden = privateThreadOf(User::factory()->create());
    $headers = phoneHeadersFor(User::factory()->create());
    $body = ['answers' => ['on_failure' => ['choices' => ['x'], 'other' => null]]];

    $missing = $this->postJson('/api/app/v1/threads/zzzzzzzz/clarification-answers', $body, $headers)->assertNotFound();
    $this->postJson(answersPath($hidden), $body, $headers)->assertNotFound()->assertExactJson($missing->json());
});

it('refuses without a token, with a revoked phone and with a computer token', function () {
    [$thread, $user] = threadWithOpenQuestions();
    $body = ['answers' => ['on_failure' => ['choices' => ['Fail the whole batch'], 'other' => null]]];

    $this->postJson(answersPath($thread), $body, ['X-Yak-Protocol' => '1'])->assertUnauthorized()->assertJsonPath('error', 'unauthenticated');
    $this->postJson('/api/app/v1/threads/zzzzzzzz/clarification-answers', $body, ['X-Yak-Protocol' => '1'])->assertUnauthorized()->assertJsonPath('error', 'unauthenticated');

    [$device, $headers] = phoneWithHeaders(['user_id' => $user->id]);
    $device->update(['revoked_at' => now()]);
    $this->postJson(answersPath($thread), $body, $headers)->assertUnauthorized()->assertJsonPath('error', 'device_revoked');

    [, $computerHeaders] = phoneWithHeaders(['user_id' => $user->id], isPhone: false);
    $this->postJson(answersPath($thread), $body, $computerHeaders)->assertUnauthorized()->assertJsonPath('error', 'invalid_token');
});

it('answers 503 unavailable when the lock cannot be taken in time', function () {
    Queue::fake();
    [$thread, $user, $task] = threadWithOpenQuestions();
    YakTask::updating(fn () => throw tap(new QueryException('mysql', 'select 1', [], new PDOException('Lock wait timeout exceeded')), fn ($exception) => $exception->errorInfo = [null, 1205, 'Lock wait timeout exceeded']));

    $this->postJson(answersPath($thread), ['answers' => [
        'on_failure' => ['choices' => ['Mark the row as failed'], 'other' => null],
        'notify' => ['choices' => ['The uploader'], 'other' => null],
    ], 'note' => null], phoneHeadersFor($user))
        ->assertStatus(503)
        ->assertExactJson(['error' => 'unavailable', 'message' => 'Yak is busy with this conversation. Try again in a few seconds.']);

    expect($task->fresh()->status)->toBe(TaskStatus::AwaitingClarification);
    Queue::assertNotPushed(RunYakJob::class);
});
