<?php

use App\Ai\Agents\PersonalityAgent;
use App\Ai\Agents\RepoRoutingAgent;
use App\Enums\NotificationType;
use App\Enums\TaskMode;
use App\Facades\Prompts;
use App\Models\Prompt;
use App\Models\YakTask;
use App\Prompts\PromptDefinitions;
use App\Prompts\PromptFixtures;
use App\Services\PromptResolver;
use App\YakPromptBuilder;

test('render returns Blade file content when prompt is not customized', function () {
    $output = Prompts::render('tasks-setup', ['repoName' => 'acme/api']);

    expect($output)->toContain('acme/api');
});

test('render returns DB content when prompt is customized', function () {
    Prompt::where('slug', 'tasks-setup')->update([
        'content' => 'Custom setup prompt for {{ $repoName }}.',
        'is_customized' => true,
    ]);

    $output = Prompts::render('tasks-setup', ['repoName' => 'acme/api']);

    expect($output)->toBe('Custom setup prompt for acme/api.');
});

test('render falls back to Blade file when DB content is blank even if customized flag is set', function () {
    Prompt::where('slug', 'tasks-setup')->update([
        'content' => '   ',
        'is_customized' => true,
    ]);

    $output = Prompts::render('tasks-setup', ['repoName' => 'acme/api']);

    expect($output)->toContain('acme/api');
});

test('render falls back to Blade file when DB content throws at render time', function () {
    Prompt::where('slug', 'tasks-setup')->update([
        'content' => '{{ $missingVariable->method() }}',
        'is_customized' => true,
    ]);

    $output = Prompts::render('tasks-setup', ['repoName' => 'acme/api']);

    expect($output)->toContain('acme/api');
});

test('YakPromptBuilder taskPrompt goes through the resolver and picks up DB overrides', function () {
    Prompt::where('slug', 'tasks-setup')->update([
        'content' => 'Custom setup for {{ $repoName }}.',
        'is_customized' => true,
    ]);

    $task = YakTask::factory()->create([
        'mode' => TaskMode::Setup,
        'repo' => 'acme/api',
    ]);

    $output = YakPromptBuilder::taskPrompt($task, ['repo_name' => 'acme/api']);

    expect($output)->toBe('Custom setup for acme/api.');
});

test('PersonalityAgent instructions go through the resolver and pick up DB overrides', function () {
    Prompt::where('slug', 'personality')->update([
        'content' => 'Voice: {{ $type }}. Context: {{ $context }}.',
        'is_customized' => true,
    ]);

    $agent = new PersonalityAgent(NotificationType::Acknowledgment->value, 'Picking up task 123');

    $output = (string) $agent->instructions();

    expect($output)->toBe('Voice: acknowledgment. Context: Picking up task 123.');
});

test('RepoRoutingAgent instructions go through the resolver and pick up DB overrides', function () {
    Prompt::where('slug', 'agents-repo-routing')->update([
        'content' => 'Pick a slug. Or UNKNOWN.',
        'is_customized' => true,
    ]);

    $agent = new RepoRoutingAgent;

    $output = (string) $agent->instructions();

    expect($output)->toBe('Pick a slug. Or UNKNOWN.');
});

test('validate rejects disallowed directives', function () {
    /** @var PromptResolver $resolver */
    $resolver = app(PromptResolver::class);

    expect($resolver->validate('Hello @include("evil")'))
        ->toBeArray()
        ->not->toBeEmpty();

    expect($resolver->validate('@extends("layout") {{ $x }}'))
        ->toBeArray()
        ->not->toBeEmpty();

    expect($resolver->validate('@php echo "boom"; @endphp'))
        ->toBeArray()
        ->not->toBeEmpty();
});

test('validate rejects Blade compile errors', function () {
    /** @var PromptResolver $resolver */
    $resolver = app(PromptResolver::class);

    $errors = $resolver->validate('@if($x');

    expect($errors)->toBeArray()->not->toBeEmpty();
});

test('validate rejects content that throws during dry-render', function () {
    /** @var PromptResolver $resolver */
    $resolver = app(PromptResolver::class);

    // No fixture passed, so the undefined method call on null blows up.
    $errors = $resolver->validate('{{ $ghost->method() }}');

    expect($errors)->toBeArray()->not->toBeEmpty();
});

test('validate returns empty array for valid content with a matching fixture', function () {
    /** @var PromptResolver $resolver */
    $resolver = app(PromptResolver::class);

    $errors = $resolver->validate('Hello {{ $name }}!', ['name' => 'World']);

    expect($errors)->toBe([]);
});

test('validate allows @include of a prompts.partials view', function () {
    /** @var PromptResolver $resolver */
    $resolver = app(PromptResolver::class);

    $errors = $resolver->validate("Hello.\n\n@include('prompts.partials.clarification-contract')");

    expect($errors)->toBe([]);
});

test('validate rejects @include outside the prompts.partials namespace', function () {
    /** @var PromptResolver $resolver */
    $resolver = app(PromptResolver::class);

    $errors = $resolver->validate("@include('layouts.app')");

    expect($errors)->toBeArray()->not->toBeEmpty();
    expect($errors[0])->toContain('prompts.partials.');
});

test('validate rejects @include with a dynamic argument', function () {
    /** @var PromptResolver $resolver */
    $resolver = app(PromptResolver::class);

    $errors = $resolver->validate('@include($view)');

    expect($errors)->toBeArray()->not->toBeEmpty();
    expect($errors[0])->toContain('prompts.partials.');
});

test('every shipped default prompt template validates cleanly', function () {
    /** @var PromptResolver $resolver */
    $resolver = app(PromptResolver::class);

    foreach (array_keys(PromptDefinitions::all()) as $slug) {
        $content = $resolver->fileContent($slug);
        $fixture = PromptFixtures::firstData($slug);

        $errors = $resolver->validate($content, $fixture);

        expect($errors)->toBe([], "Prompt [{$slug}] failed validation: " . implode(' ', $errors));
    }
});
