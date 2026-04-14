<?php

use App\Models\LinearOauthConnection;
use App\Services\LinearIssueFetcher;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('yak.channels.linear.oauth_client_id', 'cid');
    config()->set('yak.channels.linear.oauth_client_secret', 'csecret');
});

test('fetch returns null when no OAuth connection exists', function () {
    Http::fake();

    expect(app(LinearIssueFetcher::class)->fetch('uuid-no-connection'))->toBeNull();
    Http::assertNothingSent();
});

test('fetch returns null when GraphQL responds non-200', function () {
    LinearOauthConnection::factory()->create();

    Http::fake([
        'api.linear.app/graphql' => Http::response('boom', 500),
    ]);

    expect(app(LinearIssueFetcher::class)->fetch('uuid-bad'))->toBeNull();
});

test('fetch returns null when GraphQL responds with errors', function () {
    LinearOauthConnection::factory()->create();

    Http::fake([
        'api.linear.app/graphql' => Http::response([
            'errors' => [['message' => 'Issue not found']],
        ]),
    ]);

    expect(app(LinearIssueFetcher::class)->fetch('uuid-missing'))->toBeNull();
});

test('fetch returns issue payload on success', function () {
    LinearOauthConnection::factory()->create();

    Http::fake([
        'api.linear.app/graphql' => Http::response([
            'data' => [
                'issue' => [
                    'id' => 'uuid-1',
                    'identifier' => 'ENG-7',
                    'title' => 'Fix it',
                    'description' => 'Body',
                    'comments' => ['nodes' => []],
                    'attachments' => ['nodes' => []],
                ],
            ],
        ]),
    ]);

    $issue = app(LinearIssueFetcher::class)->fetch('uuid-1');

    expect($issue)->not->toBeNull()
        ->and($issue['identifier'])->toBe('ENG-7')
        ->and($issue['title'])->toBe('Fix it');
});

test('renderAsMarkdown includes header, parent, sub-issues, attachments, comments', function () {
    $markdown = app(LinearIssueFetcher::class)->renderAsMarkdown([
        'state' => ['name' => 'In Progress'],
        'priorityLabel' => 'High',
        'assignee' => ['displayName' => 'Mathias'],
        'creator' => ['displayName' => 'Bot'],
        'project' => ['name' => 'Provisioner', 'url' => 'https://linear.app/p/1'],
        'description' => 'The issue body.',
        'parent' => ['identifier' => 'ENG-1', 'title' => 'Parent goal'],
        'children' => ['nodes' => [
            ['identifier' => 'ENG-2', 'title' => 'Sub task A'],
            ['identifier' => 'ENG-3', 'title' => 'Sub task B'],
        ]],
        'attachments' => ['nodes' => [
            ['title' => 'PR #42', 'url' => 'https://github.com/x/y/pull/42', 'sourceType' => 'github'],
            ['title' => 'Design doc', 'url' => 'https://notion.so/abc', 'sourceType' => null],
        ]],
        'comments' => ['nodes' => [
            ['body' => 'First comment', 'createdAt' => '2026-04-14T09:00:00Z',
                'user' => ['displayName' => 'Alice'], 'parent' => null],
            ['body' => 'Threaded reply', 'createdAt' => '2026-04-14T09:05:00Z',
                'user' => ['displayName' => 'Bob'], 'parent' => ['id' => 'comment-1']],
            ['body' => '', 'createdAt' => '2026-04-14T09:06:00Z',
                'user' => ['displayName' => 'Empty'], 'parent' => null],
        ]],
    ]);

    expect($markdown)
        ->toContain('**State:** In Progress')
        ->toContain('**Priority:** High')
        ->toContain('**Assignee:** Mathias')
        ->toContain('**Project:** Provisioner')
        ->toContain('## Description')
        ->toContain('The issue body.')
        ->toContain('## Parent issue')
        ->toContain('ENG-1: Parent goal')
        ->toContain('## Sub-issues')
        ->toContain('ENG-2: Sub task A')
        ->toContain('## Linked attachments')
        ->toContain('[PR #42](https://github.com/x/y/pull/42) *(github)*')
        ->toContain('## Comments (2)')
        ->toContain('**Alice**')
        ->toContain('**↳ Bob**')
        ->not->toContain('**Empty**');  // empty bodies are skipped
});

test('renderAsMarkdown handles minimal payloads without errors', function () {
    $markdown = app(LinearIssueFetcher::class)->renderAsMarkdown([
        'state' => ['name' => 'Todo'],
    ]);

    expect($markdown)->toContain('**State:** Todo');
});
