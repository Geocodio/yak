<?php

use App\Models\Repository;
use App\Models\YakTask;
use App\YakPromptBuilder;

/*
|--------------------------------------------------------------------------
| System Prompt - Core Rules
|--------------------------------------------------------------------------
*/

test('system prompt contains all required rules', function () {
    $task = YakTask::factory()->pending()->create(['external_id' => 'TASK-123']);

    $prompt = YakPromptBuilder::systemPrompt($task);

    // Verify all 12 rules are present
    expect($prompt)->toContain('SCOPE')
        ->toContain('MINIMAL CHANGES')
        ->toContain('UNDERSTAND FIRST')
        ->toContain('TEST LOCALLY')
        ->toContain('COMMIT FORMAT')
        ->toContain('[TASK-123]')
        ->toContain('VISUAL CAPTURE')
        ->toContain('SCOPE CHECK')
        ->toContain('IF STUCK')
        ->toContain('CONTEXT7')
        ->toContain('DEV ENVIRONMENT')
        ->toContain('BRANCH DISCIPLINE')
        ->toContain('NO SECRETS');
});

test('system prompt includes task id in commit format rule', function () {
    $task = YakTask::factory()->pending()->create(['external_id' => 'YAK-42']);

    $prompt = YakPromptBuilder::systemPrompt($task);

    expect($prompt)->toContain('[YAK-42] Short description');
});

test('system prompt appends repo-specific agent_instructions as a notes section', function () {
    $repo = Repository::factory()->create([
        'slug' => 'acme/monorepo',
        'agent_instructions' => "Don't run the full test suite locally — CI covers it (800GB of fixture data).",
    ]);
    $task = YakTask::factory()->pending()->create(['repo' => $repo->slug]);

    $prompt = YakPromptBuilder::systemPrompt($task);

    expect($prompt)->toContain('Repository-specific notes')
        ->toContain("Don't run the full test suite locally");
});

test('system prompt omits the repo-notes section when agent_instructions is empty', function () {
    $repo = Repository::factory()->create(['slug' => 'acme/clean', 'agent_instructions' => null]);
    $task = YakTask::factory()->pending()->create(['repo' => $repo->slug]);

    $prompt = YakPromptBuilder::systemPrompt($task);

    expect($prompt)->not->toContain('Repository-specific notes');
});

test('system prompt uses custom dev environment instructions', function () {
    $task = YakTask::factory()->pending()->create();

    $prompt = YakPromptBuilder::systemPrompt($task, 'Run `docker-compose up` before testing.');

    expect($prompt)->toContain('Run `docker-compose up` before testing.');
});

test('system prompt uses default dev environment instructions when none provided', function () {
    $task = YakTask::factory()->pending()->create();

    $prompt = YakPromptBuilder::systemPrompt($task);

    expect($prompt)->toContain('No specific dev environment instructions.');
});

/*
|--------------------------------------------------------------------------
| System Prompt - Channel-Conditional Sections
|--------------------------------------------------------------------------
*/

test('system prompt does not include Linear MCP rules (Linear is handled server-side, no MCP)', function () {
    config()->set('yak.channels.linear.webhook_secret', 'test-secret');

    $task = YakTask::factory()->pending()->create();

    $prompt = YakPromptBuilder::systemPrompt($task);

    expect($prompt)->not->toContain('LINEAR MCP');
});

test('system prompt includes sentry rules when sentry channel enabled', function () {
    config()->set('yak.channels.sentry.auth_token', 'test-token');
    config()->set('yak.channels.sentry.webhook_secret', 'test-secret');
    config()->set('yak.channels.sentry.org_slug', 'test-org');

    $task = YakTask::factory()->pending()->create();

    $prompt = YakPromptBuilder::systemPrompt($task);

    expect($prompt)->toContain('SENTRY MCP');
});

test('system prompt excludes sentry rules when sentry channel disabled', function () {
    config()->set('yak.channels.sentry.auth_token', null);
    config()->set('yak.channels.sentry.webhook_secret', null);
    config()->set('yak.channels.sentry.org_slug', null);

    $task = YakTask::factory()->pending()->create();

    $prompt = YakPromptBuilder::systemPrompt($task);

    expect($prompt)->not->toContain('SENTRY MCP');
});

test('system prompt includes Sentry rules without Linear rules when both channels configured', function () {
    config()->set('yak.channels.linear.webhook_secret', 'test-secret');
    config()->set('yak.channels.sentry.auth_token', 'test-token');
    config()->set('yak.channels.sentry.webhook_secret', 'test-secret');
    config()->set('yak.channels.sentry.org_slug', 'test-org');

    $task = YakTask::factory()->pending()->create();

    $prompt = YakPromptBuilder::systemPrompt($task);

    expect($prompt)->toContain('SENTRY MCP')
        ->not->toContain('LINEAR MCP');
});

/*
|--------------------------------------------------------------------------
| Task Prompts - Sentry Fix
|--------------------------------------------------------------------------
*/

test('sentry fix prompt includes error, culprit, stacktrace, context, and instructions', function () {
    $task = YakTask::factory()->pending()->create(['source' => 'sentry']);

    $prompt = YakPromptBuilder::taskPrompt($task, [
        'error' => 'TypeError: Cannot read property of null',
        'culprit' => 'app/Services/UserService.php',
        'stacktrace' => "at UserService.php:42\nat Controller.php:15",
        'context' => 'Occurs during user registration',
        'instructions' => 'Fix the null reference error',
    ]);

    expect($prompt)->toContain('TypeError: Cannot read property of null')
        ->toContain('app/Services/UserService.php')
        ->toContain('at UserService.php:42')
        ->toContain('Occurs during user registration')
        ->toContain('Fix the null reference error');
});

/*
|--------------------------------------------------------------------------
| Task Prompts - Flaky Test Fix
|--------------------------------------------------------------------------
*/

test('flaky test prompt includes test class, method, failure output, and build url', function () {
    $task = YakTask::factory()->pending()->create(['source' => 'flaky-test']);

    $prompt = YakPromptBuilder::taskPrompt($task, [
        'test_class' => 'Tests\\Feature\\UserTest',
        'test_method' => 'test_user_can_login',
        'failure_output' => 'Expected 200 but got 500',
        'build_url' => 'https://ci.example.com/builds/123',
    ]);

    expect($prompt)->toContain('Tests\\Feature\\UserTest')
        ->toContain('test_user_can_login')
        ->toContain('Expected 200 but got 500')
        ->toContain('https://ci.example.com/builds/123');
});

test('flaky test prompt lists every observed build url when build_urls is provided', function () {
    $task = YakTask::factory()->pending()->create(['source' => 'flaky-test']);

    $prompt = YakPromptBuilder::taskPrompt($task, [
        'test_class' => 'Tests\\Feature\\UserTest',
        'test_method' => 'test_user_can_login',
        'failure_output' => 'Expected 200 but got 500',
        'build_url' => 'https://ci.example.com/builds/103',
        'build_urls' => [
            'https://ci.example.com/builds/101',
            'https://ci.example.com/builds/102',
            'https://ci.example.com/builds/103',
        ],
        'failure_count' => 3,
    ]);

    expect($prompt)->toContain('Observed failures (3)')
        ->toContain('https://ci.example.com/builds/101')
        ->toContain('https://ci.example.com/builds/102')
        ->toContain('https://ci.example.com/builds/103');
});

/*
|--------------------------------------------------------------------------
| Task Prompts - Linear Fix
|--------------------------------------------------------------------------
*/

test('linear fix prompt includes title, description, and instructions without mentioning MCP', function () {
    $task = YakTask::factory()->pending()->create(['source' => 'linear']);

    $prompt = YakPromptBuilder::taskPrompt($task, [
        'title' => 'Fix authentication bug',
        'description' => 'Users cannot login with SSO',
        'instructions' => 'Check the SAML integration',
    ]);

    expect($prompt)->toContain('Fix authentication bug')
        ->toContain('Users cannot login with SSO')
        ->toContain('Check the SAML integration')
        ->not->toContain('Linear MCP');
});

test('linear fix prompt includes the Linear issue identifier and url when present', function () {
    $task = YakTask::factory()->pending()->create(['source' => 'linear']);

    $prompt = YakPromptBuilder::taskPrompt($task, [
        'title' => 'Fix bug',
        'description' => 'Something broke',
        'linear_issue_identifier' => 'ENG-396',
        'linear_issue_url' => 'https://linear.app/team/issue/ENG-396/fix-bug',
    ]);

    expect($prompt)->toContain('ENG-396')
        ->toContain('https://linear.app/team/issue/ENG-396/fix-bug');
});

test('linear fix prompt falls back to task description when metadata missing', function () {
    $task = YakTask::factory()->pending()->create([
        'source' => 'linear',
        'description' => "Fix authentication bug\n\nUsers cannot login with SSO",
        'context' => null,
    ]);

    $prompt = YakPromptBuilder::taskPrompt($task, []);

    expect($prompt)->toContain('Fix authentication bug')
        ->toContain('Users cannot login with SSO');
});

/*
|--------------------------------------------------------------------------
| Task Prompts - Research
|--------------------------------------------------------------------------
*/

test('research prompt includes description and no-code-changes instruction', function () {
    $task = YakTask::factory()->pending()->create([
        'source' => 'research',
        'description' => 'Evaluate caching strategies for API responses',
    ]);

    $prompt = YakPromptBuilder::taskPrompt($task);

    expect($prompt)->toContain('Do NOT make any code changes')
        ->toContain('Evaluate caching strategies for API responses')
        ->toContain('HTML report')
        ->toContain('summary');
});

/*
|--------------------------------------------------------------------------
| Task Prompts - Slack Fix
|--------------------------------------------------------------------------
*/

test('slack fix prompt includes description, requester name, and ambiguity check preamble', function () {
    $task = YakTask::factory()->pending()->create([
        'source' => 'slack',
        'description' => 'The checkout page is broken',
    ]);

    $prompt = YakPromptBuilder::taskPrompt($task, [
        'requester_name' => 'Alice',
    ]);

    expect($prompt)->toContain('The checkout page is broken')
        ->toContain('Alice')
        ->toContain('clarification_needed')
        ->toContain('"options"');
});

test('slack fix prompt uses default requester name when not provided', function () {
    $task = YakTask::factory()->pending()->create([
        'source' => 'slack',
        'description' => 'Fix the bug',
    ]);

    $prompt = YakPromptBuilder::taskPrompt($task);

    expect($prompt)->toContain('a team member');
});

/*
|--------------------------------------------------------------------------
| Task Prompts - Clarification Reply
|--------------------------------------------------------------------------
*/

test('clarification reply prompt includes chosen option', function () {
    $prompt = YakPromptBuilder::clarificationReplyPrompt('Fix the auth flow');

    expect($prompt)->toContain('Fix the auth flow')
        ->toContain('Selected option')
        ->toContain('Do not ask for further clarification');
});

/*
|--------------------------------------------------------------------------
| Task Prompts - Retry
|--------------------------------------------------------------------------
*/

test('retry prompt is self-contained: original description + previous summary + CI failure', function () {
    $task = YakTask::factory()->make([
        'description' => 'Fix the duplicate CSV header in batch export',
        'result_summary' => 'Rewrote BatchExporter::writeHeader() to deduplicate.',
    ]);

    $prompt = YakPromptBuilder::retryPrompt($task, 'Tests failed: 3 failures in UserTest');

    expect($prompt)
        ->toContain('Fix the duplicate CSV header in batch export')
        ->toContain('Rewrote BatchExporter::writeHeader() to deduplicate.')
        ->toContain('Tests failed: 3 failures in UserTest')
        ->toContain('git log main..HEAD');
});

test('retry prompt omits the previous-summary block when none is stored', function () {
    $task = YakTask::factory()->make([
        'description' => 'Something',
        'result_summary' => null,
    ]);

    $prompt = YakPromptBuilder::retryPrompt($task, 'failure');

    expect($prompt)
        ->toContain('Something')
        ->not->toContain('What the previous attempt did');
});

test('retry prompt handles null failure output', function () {
    $task = YakTask::factory()->make(['description' => 'Do a thing']);

    $prompt = YakPromptBuilder::retryPrompt($task, null);

    expect($prompt)->toContain('No CI output was captured');
});

/*
|--------------------------------------------------------------------------
| Task Prompts - Default Source
|--------------------------------------------------------------------------
*/

test('unknown source falls back to slack fix template', function () {
    $task = YakTask::factory()->pending()->create([
        'source' => 'manual',
        'description' => 'Do this manually created task',
    ]);

    $prompt = YakPromptBuilder::taskPrompt($task, [
        'requester_name' => 'Bot',
    ]);

    expect($prompt)->toContain('Do this manually created task');
});

/*
|--------------------------------------------------------------------------
| Templates are Blade Views
|--------------------------------------------------------------------------
*/

test('prompt templates exist as blade views', function () {
    $views = [
        'prompts.system',
        'prompts.tasks.sentry-fix',
        'prompts.tasks.flaky-test',
        'prompts.tasks.linear-fix',
        'prompts.tasks.research',
        'prompts.tasks.slack-fix',
        'prompts.tasks.clarification-reply',
        'prompts.tasks.retry',
        'prompts.channels.sentry',
    ];

    foreach ($views as $view) {
        expect(view()->exists($view))->toBeTrue("View {$view} should exist");
    }
});
