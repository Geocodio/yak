<?php

use App\Jobs\RunYakJob;
use App\Models\User;
use App\Models\YakTask;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ContractFixture;

function clarificationBody(): array
{
    return ['answers' => ['scope' => ['choices' => ['Small'], 'other' => null]], 'note' => 'Thanks'];
}

beforeEach(function () {
    Queue::fake();
    $this->user = User::factory()->create(['name' => 'Test person']);
    $this->headers = phoneHeadersFor($this->user);
    $this->task = YakTask::factory()->withClarificationQuestions()->create(['public_id' => 'k3m9p2qa', 'source' => 'linear', 'started_by_user_id' => $this->user->id, 'thread_id' => null]);
});

it('answers a task outside a thread', function () {
    $response = $this->postJson(appTaskPath($this->task, '/clarification-answers'), clarificationBody(), $this->headers)->assertOk()->assertExactJson(['status' => 'sent']);

    expect($this->task->fresh()->clarificationAnswersAwaitingResume()['answered_by'])->toBe('Test person');
    Queue::assertPushed(RunYakJob::class, 1);
    ContractFixture::assertMatches('app', 'task-clarification-answers', $response->json());
});

it('refuses closed questions', function () {
    $this->postJson(appTaskPath($this->task, '/clarification-answers'), clarificationBody(), $this->headers)->assertOk();

    $this->postJson(appTaskPath($this->task, '/clarification-answers'), clarificationBody(), $this->headers)
        ->assertStatus(409)->assertJsonPath('error', 'question_closed')->assertJsonPath('message', 'These questions were already answered.');
    Queue::assertPushed(RunYakJob::class, 1);
});

it('validates answers that do not match', function (array $payload, string $field) {
    $response = $this->postJson(appTaskPath($this->task, '/clarification-answers'), $payload, $this->headers)->assertStatus(422)->assertJsonPath('error', 'validation_failed');

    expect($response->json('details.errors'))->toHaveKey($field);
    Queue::assertNotPushed(RunYakJob::class);
})->with([
    'nothing answered' => [['answers' => ['scope' => ['choices' => [], 'other' => '  ']]], 'answers'],
    'unknown question' => [['answers' => ['nope' => ['choices' => ['Small'], 'other' => null]]], 'answers'],
    'unknown label' => [['answers' => ['scope' => ['choices' => ['Huge'], 'other' => null]]], 'answers.scope'],
    'too many answers' => [['answers' => array_fill_keys(range(1, 11), ['choices' => ['Small'], 'other' => null])], 'answers'],
    'long note' => [['answers' => ['scope' => ['choices' => ['Small'], 'other' => null]], 'note' => str_repeat('a', 2001)], 'note'],
]);

describe('who may answer', function () {
    beforeEach(function () {
        $this->thread = privateThreadOf($this->user, ['public_id' => 'd0e515ef']);
        $this->task->update(['thread_id' => $this->thread->id]);
    });

    it('refuses a share recipient', function () {
        $this->postJson(appTaskPath($this->task, '/clarification-answers'), clarificationBody(), phoneHeadersFor(shareThreadWith($this->thread, $this->user)))
            ->assertForbidden()->assertJsonPath('error', 'forbidden');

        Queue::assertNotPushed(RunYakJob::class);
    });

    it('answers a hidden task exactly like a missing one', function () {
        $stranger = phoneHeadersFor(User::factory()->create());
        $missing = (clone $this->task)->forceFill(['public_id' => 'nope0000']);

        $hidden = $this->postJson(appTaskPath($this->task, '/clarification-answers'), clarificationBody(), $stranger)->assertNotFound();
        $unknown = $this->postJson(appTaskPath($missing, '/clarification-answers'), clarificationBody(), $stranger)->assertNotFound();

        expect($hidden->json())->toBe($unknown->json());
    });

    it('refuses without a token, with a revoked phone and with a computer token', function () {
        $this->postJson(appTaskPath($this->task, '/clarification-answers'), clarificationBody(), ['X-Yak-Protocol' => '1'])->assertUnauthorized()->assertJsonPath('error', 'unauthenticated');

        [$device, $headers] = phoneWithHeaders(['user_id' => $this->user->id]);
        $device->update(['revoked_at' => now()]);
        $this->postJson(appTaskPath($this->task, '/clarification-answers'), clarificationBody(), $headers)->assertUnauthorized()->assertJsonPath('error', 'device_revoked');

        [, $computerHeaders] = phoneWithHeaders(['user_id' => $this->user->id], isPhone: false);
        $this->postJson(appTaskPath($this->task, '/clarification-answers'), clarificationBody(), $computerHeaders)->assertUnauthorized()->assertJsonPath('error', 'invalid_token');
    });
});
