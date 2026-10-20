<?php

use App\Channels\Slack\CoOwnerBlocks;
use App\Enums\CoOwnerRequestStatus;
use App\Models\CoOwnerRequest;
use App\Models\Repository;
use App\Models\User;
use App\Models\YakTask;

function heldRequest(array $attributes = [], array $taskAttributes = []): CoOwnerRequest
{
    $repository = Repository::firstOrCreate(['slug' => 'acme/web'], Repository::factory()->raw(['github_full_name' => 'acme/web', 'default_branch' => 'main']));
    $task = YakTask::factory()->awaitingCoOwner()->create([
        'repo' => 'acme/web',
        'branch_name' => 'yak/fix-login',
        'author_name' => 'Jane Doe',
        'source' => 'slack',
        ...$taskAttributes,
    ]);

    return CoOwnerRequest::factory()->create([
        'task_id' => $task->id,
        'repository_id' => $repository->id,
        'matched_areas' => [['name' => 'API response pipeline', 'risk' => 'high', 'rationale' => 'It shapes every response.', 'paths' => ['app/Http/Middleware/Authenticate.php', 'app/new.php']]],
        'changed_files' => [
            ['path' => 'app/Http/Middleware/Authenticate.php', 'previousPath' => null, 'status' => 'modified', 'additions' => 12, 'deletions' => 3, 'isHeld' => true],
            ['path' => 'app/new.php', 'previousPath' => null, 'status' => 'new', 'additions' => 30, 'deletions' => 0, 'isHeld' => true],
        ],
        'held_at' => now()->setTime(14, 5),
        ...$attributes,
    ]);
}

function allText(array $blocks): string
{
    return json_encode($blocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function actionIds(array $blocks): array
{
    return collect($blocks)->where('type', 'actions')->flatMap(fn ($block) => collect($block['elements'])->pluck('action_id'))->all();
}

it('words the held thread reply with the areas, rationale and who was asked', function () {
    $second = User::factory()->create(['name' => 'Sam Lee']);
    $first = User::factory()->create(['name' => 'Ada Ray']);
    $request = heldRequest(['asked_user_ids' => [$second->id, $first->id]]);

    $text = allText(CoOwnerBlocks::threadHeld($request));

    expect($text)
        ->toContain('Your change is built and CI passed. It needs a co-owner before I open a PR.')
        ->toContain('It touches API response pipeline (high risk). It shapes every response. Changes to areas like this get a second person with write access to acme/web, whoever asks for them.')
        ->toContain("I've asked Sam Lee and Ada Ray. Anyone with write access to acme/web can pick it up, so feel free to share the task link.")
        ->toContain('CI passed on yak/fix-login')
        ->toContain('Held since 14:05 · expires in 7 days')
        ->toContain('https://github.com/acme/web/compare/main...yak/fix-login');
    expect(actionIds(CoOwnerBlocks::threadHeld($request)))->toBe(['yak_link_view_changes', 'yak_link_open_task', 'yak_coowner_withdraw']);
});

it('joins several areas with a comma and "and"', function () {
    $request = heldRequest(['matched_areas' => [
        ['name' => 'A', 'risk' => 'high', 'rationale' => 'r', 'paths' => []],
        ['name' => 'B', 'risk' => 'critical', 'rationale' => 'worse', 'paths' => []],
        ['name' => 'C', 'risk' => 'high', 'rationale' => 'r', 'paths' => []],
    ]]);

    expect(allText(CoOwnerBlocks::threadHeld($request)))->toContain('It touches A (high risk), B (critical risk) and C (high risk). worse');
});

it('explains a floor-only hold', function () {
    $request = heldRequest(['hold_reasons' => ['floor'], 'matched_areas' => []]);

    expect(allText(CoOwnerBlocks::threadHeld($request)))->toContain("It changes Yak's own config files, which always need a co-owner.");
});

it('explains a hold where files could not be checked', function () {
    $request = heldRequest(['hold_reasons' => ['unchecked'], 'matched_areas' => []]);

    expect(allText(CoOwnerBlocks::threadHeld($request)))->toContain('Yak could not check every changed file against the risk profile, so it holds the change to be safe.');
});

it('says so when nobody could be asked', function () {
    $request = heldRequest(['asked_user_ids' => []]);

    expect(allText(CoOwnerBlocks::threadHeld($request)))->toContain('Nobody could be asked yet: no CODEOWNERS owner or fallback owner resolves to a linked user with write access. Anyone with write access to acme/web can approve from the task page.');
});

it('builds the direct message with the details and three buttons', function () {
    $request = heldRequest();
    $blocks = CoOwnerBlocks::directMessage($request, User::factory()->create());
    $text = allText($blocks);

    expect($blocks[0]['text']['text'])->toBe('Jane Doe needs a co-owner for a change');
    expect($text)
        ->toContain('Yak finished a task for Jane and CI passed. It touches an area the acme/web risk profile rates high, so a second person with write access takes a look before a PR opens. Would you co-own it?')
        ->toContain('*Matched area*\nAPI response pipeline (high)\nIt shapes every response.')
        ->toContain('`app/new.php` new')
        ->not->toContain('`app/Http/Middleware/Authenticate.php` new')
        ->toContain('+42 −3 across 2 files')
        ->toContain('*CI*\nPassed')
        ->toContain('<https://github.com/acme/web/compare/main...yak/fix-login|View changes on GitHub>')
        ->toContain('Approve & open PR')->toContain('Request changes')->toContain('Reject')
        ->toContain('Held since 14:05 · expires in 7 days · Approving makes you the responsible reviewer');
    expect(actionIds($blocks))->toBe(['yak_coowner_approve', 'yak_link_request_changes', 'yak_coowner_reject']);

    $buttons = $blocks[4]['elements'];
    expect($buttons[0]['style'])->toBe('primary')
        ->and($buttons[0]['value'])->toBe((string) $request->id)
        ->and($buttons[2]['style'])->toBe('danger')
        ->and($buttons[2])->toHaveKey('confirm')
        ->and($buttons[1]['url'])->toEndWith("/tasks/{$request->task_id}#co-owner");
});

it('escapes Slack control characters in area names and paths', function () {
    $request = heldRequest(['matched_areas' => [['name' => '<!channel> & co', 'risk' => 'high', 'rationale' => '<@U1> look', 'paths' => ['a/<b>.php']]]]);

    $text = allText([...CoOwnerBlocks::threadHeld($request), ...CoOwnerBlocks::directMessage($request, User::factory()->create())]);

    expect($text)->toContain('&lt;!channel&gt; &amp; co')->toContain('&lt;@U1&gt; look')->toContain('a/&lt;b&gt;.php')
        ->not->toContain('<!channel>');
});

it('builds the reminder with the one-line summary and the buttons', function () {
    $request = heldRequest();
    $text = allText(CoOwnerBlocks::reminder($request));

    expect($text)
        ->toContain("Still waiting: Jane's change to acme/web has been held for 2 days.")
        ->toContain('API response pipeline ● High  ·  Requested by Jane Doe  ·  ✓ CI passed  ·  +42 −3')
        ->toContain('Expires in 5 days');
    expect(actionIds(CoOwnerBlocks::reminder($request)))->toBe(['yak_coowner_approve', 'yak_link_request_changes', 'yak_coowner_reject']);
    expect(CoOwnerBlocks::reminderNoteText($request))->toBe('Still waiting for a co-owner. Nobody could be asked yet, so anyone with write access to acme/web can approve from the task page. The request expires in 5 days.');

    $asked = User::factory()->create(['name' => 'Sam Lee']);
    $request->update(['asked_user_ids' => [$asked->id]]);
    expect(CoOwnerBlocks::reminderNoteText($request->refresh()))->toBe('Still waiting for a co-owner. I asked Sam Lee again. The request expires in 5 days.');
});

it('words the resolved direct message for each outcome', function (CoOwnerRequestStatus $status, string $expected) {
    $decider = User::factory()->create(['name' => 'Sam Lee']);
    $request = heldRequest(['status' => $status, 'decided_by_user_id' => $decider->id, 'decided_at' => now()->setTime(15, 30)]);

    $blocks = CoOwnerBlocks::resolved($request, User::factory()->create());

    expect($blocks[0]['text']['text'])->toBe($expected);
    expect(actionIds($blocks))->toBe([]);
})->with([
    'rejected' => [CoOwnerRequestStatus::Rejected, 'Rejected by Sam Lee at 3:30 PM. The branch was deleted and no PR was opened.'],
    'withdrawn' => [CoOwnerRequestStatus::Withdrawn, 'Withdrawn by Sam Lee at 3:30 PM. No action needed. The branch was deleted and no PR was opened.'],
    'changes requested' => [CoOwnerRequestStatus::ChangesRequested, 'Sam Lee requested changes at 3:30 PM. Yak is running a follow-up and will ask again.'],
    'expired' => [CoOwnerRequestStatus::Expired, 'Expired. Nobody decided within 7 days, so the task was closed and no PR was opened. If you still want it, Jane can ask Yak again in the thread.'],
    'superseded' => [CoOwnerRequestStatus::Superseded, 'Replaced by a newer run of this task. No action needed.'],
]);

it('reads without a name when a withdraw has no decider', function () {
    $request = heldRequest(['status' => CoOwnerRequestStatus::Withdrawn, 'decided_by_user_id' => null, 'decided_at' => now()->setTime(9, 0)]);

    expect(CoOwnerBlocks::resolved($request, User::factory()->create())[0]['text']['text'])
        ->toBe('Withdrawn at 9:00 AM. No action needed. The branch was deleted and no PR was opened.');
});

it('words the approved direct message for the approver and for others', function () {
    $approver = User::factory()->create(['name' => 'Sam Lee']);
    $other = User::factory()->create();
    $request = heldRequest(
        ['status' => CoOwnerRequestStatus::Approved, 'decided_by_user_id' => $approver->id, 'decided_at' => now()->setTime(15, 30)],
        ['pr_url' => 'https://github.com/acme/web/pull/42', 'pr_number' => 42],
    );

    $forApprover = CoOwnerBlocks::resolved($request, $approver);
    $forOther = CoOwnerBlocks::resolved($request, $other);

    expect($forApprover[0]['text']['text'])->toBe("Approved by Sam Lee. PR #42 opened, you're the responsible reviewer.")
        ->and($forOther[0]['text']['text'])->toBe('Approved by Sam Lee. PR #42 is open.')
        ->and($forApprover[1]['elements'][0])->toMatchArray(['action_id' => 'yak_link_view_pr', 'url' => 'https://github.com/acme/web/pull/42'])
        ->and($forApprover[1]['elements'][0]['text']['text'])->toBe('View PR #42')
        ->and($forApprover[2]['elements'][0]['text'])->toBe('Approved 3:30 PM · Jane has been told in the original thread');
});

it('words the thread notes for rejected, changes requested and expired requests', function () {
    $decider = User::factory()->create(['name' => 'Sam Lee']);

    $rejected = heldRequest(['status' => CoOwnerRequestStatus::Rejected, 'decided_by_user_id' => $decider->id]);
    expect(CoOwnerBlocks::threadResolved($rejected)[0]['text']['text'])->toBe('Sam Lee rejected this change, so no PR was opened. The branch was deleted.');

    $changes = heldRequest(['status' => CoOwnerRequestStatus::ChangesRequested, 'decided_by_user_id' => $decider->id]);
    expect(CoOwnerBlocks::threadResolved($changes)[0]['text']['text'])->toBe("Sam Lee asked for changes. I'm working on them and will ask again.");

    $expired = heldRequest(['status' => CoOwnerRequestStatus::Expired]);
    $blocks = CoOwnerBlocks::threadResolved($expired);
    expect($blocks[0]['text']['text'])->toBe('This request expired after 7 days without a co-owner. The task is closed. Nobody approved or rejected it, so no PR was opened. The branch yak/fix-login is kept for now.')
        ->and($blocks[1]['text']['text'])->toBe('To pick it up again, ask me in this thread, for example @Yak try this again, and I will start a new run and hold it for a co-owner.')
        ->and(actionIds($blocks))->toBe(['yak_link_open_task']);

    expect(CoOwnerBlocks::threadResolved(heldRequest(['status' => CoOwnerRequestStatus::Approved])))->toBe([]);
});

it('explains a floor hold and an unchecked hold in the direct message without a risk level', function () {
    $floor = heldRequest(['hold_reasons' => ['floor'], 'matched_areas' => []]);
    $blocks = CoOwnerBlocks::directMessage($floor, User::factory()->create());
    $text = allText($blocks);

    expect($blocks[1]['text']['text'])->toBe("Yak finished a task for Jane and CI passed. It changes Yak's own config files, which always need a co-owner. Would you co-own it?");
    expect($text)->toContain('*Why it is held*')->toContain('`app/new.php`')->not->toContain('rates unknown')->not->toContain('Matched area');

    $unchecked = heldRequest(['hold_reasons' => ['unchecked'], 'matched_areas' => []]);
    expect(CoOwnerBlocks::directMessage($unchecked, User::factory()->create())[1]['text']['text'])
        ->toBe('Yak finished a task for Jane and CI passed. Yak could not check every changed file against the risk profile, so it holds the change to be safe. Would you co-own it?');
});

it('escapes and encodes a hostile branch name', function () {
    $request = heldRequest([], ['branch_name' => 'yak/a<b>|c&d']);

    $text = allText(CoOwnerBlocks::threadHeld($request));

    expect($text)->toContain('CI passed on yak/a&lt;b&gt;|c&amp;d')
        ->toContain('compare/main...yak/a%3Cb%3E%7Cc%26d')
        ->not->toContain('yak/a<b>');
});

it('keeps a long rationale under the field limit and has no double space for an empty one', function () {
    $long = heldRequest(['matched_areas' => [['name' => 'A', 'risk' => 'high', 'rationale' => str_repeat('x', 5000), 'paths' => []]]]);
    $fields = CoOwnerBlocks::directMessage($long, User::factory()->create())[2]['fields'];
    expect(mb_strlen($fields[1]['text']))->toBeLessThan(2000);

    $empty = heldRequest(['matched_areas' => [['name' => 'A', 'risk' => 'high', 'rationale' => '', 'paths' => []]]]);
    expect(CoOwnerBlocks::threadHeld($empty)[1]['text']['text'])->toContain('It touches A (high risk). Changes to areas');
});

it('falls back to a generic name and skips the PR button when data is missing', function () {
    $request = heldRequest(['status' => CoOwnerRequestStatus::Rejected, 'decided_by_user_id' => null, 'decided_at' => now()->setTime(9, 0)]);
    expect(CoOwnerBlocks::resolved($request, User::factory()->create())[0]['text']['text'])->toStartWith('Rejected by A co-owner at 9:00 AM');

    $approved = heldRequest(['status' => CoOwnerRequestStatus::Approved, 'decided_by_user_id' => null, 'decided_at' => now()], ['pr_url' => null, 'pr_number' => 7]);
    expect(actionIds(CoOwnerBlocks::resolved($approved, User::factory()->create())))->toBe([]);
});
