<?php

use App\Models\ClaudeAccount;
use App\Models\Thread;
use App\Services\Push\PushEvent;
use App\Services\Push\PushPayloadFactory;

it('carries the title and a relative url for a team thread', function () {
    $thread = Thread::factory()->create(['title' => 'Fix the geocoder']);

    $payload = app(PushPayloadFactory::class)->forThread(PushEvent::TurnFinished, $thread, 'Turn done');

    expect($payload['title'])->toBe('Fix the geocoder')
        ->and($payload['body'])->toBe('Turn done')
        ->and($payload['url'])->toStartWith('/threads/')
        ->and($payload['tag'])->toBe("thread-{$thread->id}-turn_finished");
});

it('lets a caller override the tag', function () {
    $thread = Thread::factory()->create();

    expect(app(PushPayloadFactory::class)->forThread(PushEvent::StackUsage, $thread, 'x', 'stack-1')['tag'])->toBe('stack-1');
});

it('leaks nothing about a private thread', function () {
    $thread = Thread::factory()->private()->create(['title' => 'Secret migration plan', 'branch' => 'secret-branch']);

    $payload = app(PushPayloadFactory::class)->forThread(PushEvent::NeedsYou, $thread, 'Secret body text');
    $serialized = json_encode($payload);

    expect($payload['title'])->toBe('Private thread')
        ->and($payload['body'])->toBe('Open Yak to read it.')
        ->and($serialized)->not->toContain('Secret')
        ->not->toContain('secret-branch')
        ->not->toContain('acme/widgets');
});

it('builds an account payload without any thread', function () {
    $account = ClaudeAccount::factory()->create(['name' => 'Work Max']);
    Thread::factory()->create(['title' => 'Blocked thread', 'claude_account_id' => $account->id]);

    $payload = app(PushPayloadFactory::class)->forAccount($account, 'Needs sign-in');

    expect($payload)->toBe(['title' => 'Work Max', 'body' => 'Needs sign-in', 'url' => '/settings/claude-accounts', 'tag' => "account-{$account->id}"])
        ->and(json_encode($payload))->not->toContain('Blocked thread');
});
