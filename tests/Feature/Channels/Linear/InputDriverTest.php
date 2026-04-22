<?php

use App\Ai\Agents\TaskIntentClassifier;
use App\Channels\Linear\InputDriver as LinearInputDriver;
use App\Enums\TaskMode;
use App\Models\Repository;
use Illuminate\Http\Request;

function agentSessionCreatedRequest(array $overrides = []): Request
{
    $payload = [
        'type' => 'AgentSessionEvent',
        'action' => 'created',
        'organizationId' => 'workspace-uuid-001',
        'agentSession' => [
            'id' => $overrides['sessionId'] ?? 'session-uuid-001',
            'issue' => [
                'id' => $overrides['issueId'] ?? 'issue-uuid-001',
                'identifier' => $overrides['identifier'] ?? 'ENG-42',
                'title' => $overrides['title'] ?? 'Fix the broken auth flow',
                'description' => $overrides['description'] ?? 'Intermittent 500 on login.',
                'url' => $overrides['url'] ?? 'https://linear.app/team/issue/ENG-42',
            ],
            'promptContext' => $overrides['promptContext'] ?? '',
        ],
    ];

    return Request::create(
        '/webhooks/linear',
        'POST',
        server: ['CONTENT_TYPE' => 'application/json'],
        content: (string) json_encode($payload),
    );
}

it('parses AgentSessionEvent.created into a normalized TaskDescription', function () {
    $description = (new LinearInputDriver)->parse(agentSessionCreatedRequest());

    expect($description->channel)->toBe('linear');
    expect($description->externalId)->toBe('LINEAR-ENG-42');
    expect($description->title)->toBe('Fix the broken auth flow');
    expect($description->body)->toContain('Fix the broken auth flow');
    expect($description->body)->toContain('Intermittent 500 on login.');
    expect($description->metadata['mode'])->toBe(TaskMode::Fix->value);
    expect($description->metadata['linear_issue_id'])->toBe('issue-uuid-001');
    expect($description->metadata['linear_issue_identifier'])->toBe('ENG-42');
    expect($description->metadata['linear_issue_url'])->toBe('https://linear.app/team/issue/ENG-42');
    expect($description->metadata['linear_agent_session_id'])->toBe('session-uuid-001');
});

it('detects research mode from the word "research" in the issue title', function () {
    $description = (new LinearInputDriver)->parse(agentSessionCreatedRequest([
        'title' => 'Research: audit deprecated fields',
    ]));

    expect($description->metadata['mode'])->toBe(TaskMode::Research->value);
});

it('falls back to the intent classifier when title and labels do not signal research', function () {
    config(['yak.intent_classifier.enabled' => true]);
    TaskIntentClassifier::fake(['research']);

    $description = (new LinearInputDriver)->parse(agentSessionCreatedRequest([
        'title' => 'How does the export job work?',
        'description' => 'Need to understand the retry behavior before we touch it.',
    ]));

    expect($description->metadata['mode'])->toBe(TaskMode::Research->value);
});

it('skips the classifier when the title already matches research', function () {
    config(['yak.intent_classifier.enabled' => true]);
    TaskIntentClassifier::fake()->preventStrayPrompts();

    $description = (new LinearInputDriver)->parse(agentSessionCreatedRequest([
        'title' => 'Research: deprecate internal API',
    ]));

    expect($description->metadata['mode'])->toBe(TaskMode::Research->value);
});

it('detects repo from explicit "repo: owner/name" in the issue description', function () {
    Repository::factory()->create(['slug' => 'acme/api']);

    $description = (new LinearInputDriver)->parse(agentSessionCreatedRequest([
        'description' => "The middleware is broken.\n\nrepo: acme/api",
    ]));

    expect($description->repository)->toBe('acme/api');
});

it('inlines the rendered promptContext markdown into the task body when present', function () {
    $xml = <<<'XML'
    <issue identifier="ENG-42"><title>Fix auth</title><description>Main desc</description></issue>
    <primary-directive-thread comment-id="c1">
    <comment author="Jane" created-at="2026-01-01 10:00:00">Please handle this</comment>
    </primary-directive-thread>
    XML;

    $description = (new LinearInputDriver)->parse(agentSessionCreatedRequest([
        'promptContext' => $xml,
    ]));

    expect($description->body)->toContain('Please handle this');
    expect($description->body)->toContain('## Primary directive');
});
