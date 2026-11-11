<?php

use App\Enums\StackMessageKind;
use App\Enums\ThreadKind;
use App\Models\StackMessage;
use App\Models\Thread;
use App\Services\Teleport\StackMessageInbox;

beforeEach(function () {
    [$this->device, $this->token] = makeDevice();
    $this->thread = Thread::factory()->ownedByDevice($this->device->id)->create();
    $this->inbox = app(StackMessageInbox::class);
});

it('returns pending messages oldest first with a limit and has_more', function () {
    $research = Thread::factory()->create(['title' => 'Research: cache backends', 'kind' => ThreadKind::Research]);
    $first = $this->inbox->record($this->thread, StackMessageKind::Result, $research, 'one');
    $this->inbox->record($this->thread, StackMessageKind::Note, null, 'two');
    $this->inbox->record($this->thread, StackMessageKind::Failure, null, 'three');

    $response = $this->getJson("/api/cli/v1/threads/{$this->thread->id}/stack-messages?limit=2", cliHeaders($this->token))->assertOk();

    expect($response->json('messages'))->toHaveCount(2)
        ->and($response->json('has_more'))->toBeTrue()
        ->and($response->json('owner_side'))->toBe('device')
        ->and($response->json('messages.0'))->toMatchArray(['id' => $first->id, 'kind' => 'result', 'text' => 'one', 'from_thread' => ['id' => $research->id, 'title' => 'Research: cache backends', 'kind' => 'research']])
        ->and($response->json('messages.1.from_thread'))->toBeNull();
    expect(StackMessage::query()->whereNull('delivered_at')->count())->toBe(3);
});

it('caps the limit at 50 and caps the text at 20000 characters', function () {
    $this->inbox->record($this->thread, StackMessageKind::Note, null, str_repeat('x', 30000));

    $response = $this->getJson("/api/cli/v1/threads/{$this->thread->id}/stack-messages?limit=500", cliHeaders($this->token))->assertOk();

    expect(mb_strlen($response->json('messages.0.text')))->toBe(20000)->and($response->json('has_more'))->toBeFalse();
});

it('answers not_owner for another device and for a Yak-owned thread', function () {
    [, $otherToken] = makeDevice($this->device->user);
    $yakOwned = Thread::factory()->create();

    $this->getJson("/api/cli/v1/threads/{$this->thread->id}/stack-messages", cliHeaders($otherToken))->assertStatus(409)->assertJsonPath('error', 'not_owner');
    $this->getJson("/api/cli/v1/threads/{$yakOwned->id}/stack-messages", cliHeaders($this->token))->assertStatus(409)->assertJsonPath('error', 'not_owner');
    $this->postJson("/api/cli/v1/threads/{$this->thread->id}/stack-messages/ack", ['through_id' => 1], cliHeaders($otherToken))->assertStatus(409);
});

it('acks monotonically and idempotently', function () {
    $first = $this->inbox->record($this->thread, StackMessageKind::Note, null, 'one');
    $second = $this->inbox->record($this->thread, StackMessageKind::Note, null, 'two');
    $third = $this->inbox->record($this->thread, StackMessageKind::Note, null, 'three');
    $url = "/api/cli/v1/threads/{$this->thread->id}/stack-messages/ack";

    $this->postJson($url, ['through_id' => $second->id], cliHeaders($this->token))->assertOk()->assertJsonPath('acked', 2);
    $this->postJson($url, ['through_id' => $second->id], cliHeaders($this->token))->assertOk()->assertJsonPath('acked', 0);
    $this->postJson($url, ['through_id' => $first->id], cliHeaders($this->token))->assertOk()->assertJsonPath('acked', 0);

    $remaining = $this->getJson("/api/cli/v1/threads/{$this->thread->id}/stack-messages", cliHeaders($this->token))->json('messages');
    expect(collect($remaining)->pluck('id')->all())->toBe([$third->id]);
});

it('requires a through_id to ack', function () {
    $this->postJson("/api/cli/v1/threads/{$this->thread->id}/stack-messages/ack", [], cliHeaders($this->token))->assertStatus(422);
});
