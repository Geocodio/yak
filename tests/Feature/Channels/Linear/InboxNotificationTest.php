<?php

use App\Enums\TaskStatus;
use App\Models\YakTask;
use Illuminate\Support\Facades\Http;

// Helpers shared with WebhookTest are loaded via the test file itself;
// enableLinearChannel(), linearConnection(), signLinearPayload(), and
// postLinearWebhook() are all available because Pest autoloads sibling
// test files in the same directory.

function inboxNotificationPayload(array $overrides = []): string
{
    return (string) json_encode([
        'action' => $overrides['action'] ?? 'create',
        'organizationId' => $overrides['organizationId'] ?? TEST_WORKSPACE_ID,
        'notification' => array_merge([
            'type' => $overrides['type'] ?? 'issueUnassignedFromYou',
            'issue' => [
                'id' => $overrides['issueId'] ?? 'issue-abc',
                'identifier' => $overrides['identifier'] ?? 'ENG-10',
            ],
        ], $overrides['notification'] ?? []),
    ]);
}

// --- Unassignment cancels in-flight work ---

it('cancels a running linear task when unassigned from the issue', function () {
    $secret = enableLinearChannel();
    linearConnection();
    Http::fake(['*' => Http::response(['data' => ['agentActivityCreate' => ['success' => true]]])]);

    $task = YakTask::factory()->running()->create([
        'source' => 'linear',
        'linear_agent_session_id' => 'session-unassign-001',
        'context' => json_encode([
            'linear_issue_id' => 'issue-abc',
            'linear_issue_identifier' => 'ENG-10',
        ]),
    ]);

    $body = inboxNotificationPayload(['issueId' => 'issue-abc', 'type' => 'issueUnassignedFromYou']);

    postLinearWebhook($body, $secret, 'InboxNotificationEvent')->assertSuccessful();

    expect($task->fresh()->status)->toBe(TaskStatus::Cancelled);
});

it('does not cancel a terminal (success) task on unassignment', function () {
    $secret = enableLinearChannel();
    linearConnection();
    Http::fake(['*' => Http::response(['data' => ['agentActivityCreate' => ['success' => true]]])]);

    $task = YakTask::factory()->success()->create([
        'source' => 'linear',
        'context' => json_encode(['linear_issue_id' => 'issue-done']),
    ]);

    $body = inboxNotificationPayload(['issueId' => 'issue-done', 'type' => 'issueUnassignedFromYou']);

    postLinearWebhook($body, $secret, 'InboxNotificationEvent')->assertSuccessful();

    expect($task->fresh()->status)->toBe(TaskStatus::Success);
});

it('returns 200 ok on unassignment when no matching task exists', function () {
    $secret = enableLinearChannel();
    linearConnection();
    Http::fake();

    $body = inboxNotificationPayload(['issueId' => 'issue-no-task', 'type' => 'issueUnassignedFromYou']);

    postLinearWebhook($body, $secret, 'InboxNotificationEvent')->assertSuccessful();

    expect(YakTask::count())->toBe(0);
    Http::assertNothingSent();
});

// --- Reactions are acknowledged without error ---

it('returns 200 ok for a reaction inbox notification without touching tasks', function () {
    $secret = enableLinearChannel();
    linearConnection();
    Http::fake();

    $task = YakTask::factory()->running()->create([
        'source' => 'linear',
        'context' => json_encode(['linear_issue_id' => 'issue-reacted']),
    ]);

    $body = inboxNotificationPayload(['issueId' => 'issue-reacted', 'type' => 'issueEmojiReaction']);

    postLinearWebhook($body, $secret, 'InboxNotificationEvent')->assertSuccessful();

    expect($task->fresh()->status)->toBe(TaskStatus::Running);
});

// --- Unrelated inbox types are ignored ---

it('ignores unrelated inbox notification types without changing tasks', function () {
    $secret = enableLinearChannel();
    linearConnection();
    Http::fake();

    $task = YakTask::factory()->running()->create([
        'source' => 'linear',
        'context' => json_encode(['linear_issue_id' => 'issue-other']),
    ]);

    $body = inboxNotificationPayload(['issueId' => 'issue-other', 'type' => 'issueAssignedToYou']);

    postLinearWebhook($body, $secret, 'InboxNotificationEvent')->assertSuccessful();

    expect($task->fresh()->status)->toBe(TaskStatus::Running);
    Http::assertNothingSent();
});

// --- Connection guard ---

it('returns 200 ok for an inbox notification from an unknown workspace', function () {
    $secret = enableLinearChannel();
    linearConnection();
    Http::fake();

    $body = inboxNotificationPayload([
        'organizationId' => 'unknown-workspace',
        'type' => 'issueUnassignedFromYou',
        'issueId' => 'issue-abc',
    ]);

    postLinearWebhook($body, $secret, 'InboxNotificationEvent')->assertSuccessful();

    Http::assertNothingSent();
});

// --- Security: LIKE wildcard injection ---

test('an unassignment with a wildcard issue id cancels nothing (LIKE injection blocked)', function () {
    $secret = enableLinearChannel();
    linearConnection();
    Http::fake();

    // A legitimate in-flight task with a real issue id in context.
    $task = YakTask::factory()->running()->create([
        'source' => 'linear',
        'linear_agent_session_id' => 'sess-wildcard-test',
        'context' => json_encode([
            'linear_issue_id' => 'issue-legit-123',
            'linear_issue_identifier' => 'ENG-99',
        ]),
    ]);

    // Attacker sends issueId='%' — a LIKE wildcard that would match everything.
    $body = inboxNotificationPayload(['issueId' => '%', 'type' => 'issueUnassignedFromYou']);

    postLinearWebhook($body, $secret, 'InboxNotificationEvent')->assertSuccessful();

    // The wildcard must NOT cancel the legitimate task.
    expect($task->fresh()->status)->toBe(TaskStatus::Running);

    // No Linear API calls should have been made.
    Http::assertNothingSent();
});

// --- Robustness: payload with missing fields ---

it('handles inbox notifications with a missing notification.issue gracefully', function () {
    $secret = enableLinearChannel();
    linearConnection();
    Http::fake();

    $body = (string) json_encode([
        'action' => 'create',
        'organizationId' => TEST_WORKSPACE_ID,
        'notification' => [
            'type' => 'issueUnassignedFromYou',
            // 'issue' intentionally missing
        ],
    ]);

    postLinearWebhook($body, $secret, 'InboxNotificationEvent')->assertSuccessful();
});
