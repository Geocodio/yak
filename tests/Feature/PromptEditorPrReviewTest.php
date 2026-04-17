<?php

use App\Prompts\PromptDefinitions;
use App\Prompts\PromptFixtures;
use App\Services\PromptResolver;

it('registers the tasks-review prompt definition', function () {
    expect(PromptDefinitions::has('tasks-review'))->toBeTrue();

    $def = PromptDefinitions::for('tasks-review');

    expect($def['view'])->toBe('prompts.tasks.review')
        ->and($def['label'])->toBe('PR Review')
        ->and($def['variables'])->toContain('prNumber', 'prTitle', 'pathExcludes', 'linearTicket');
});

it('renders the review prompt against the fixture', function () {
    $fixture = PromptFixtures::firstData('tasks-review');
    $rendered = app(PromptResolver::class)->render('tasks-review', $fixture);

    expect($rendered)->not->toBeEmpty()
        ->and($rendered)->toContain('PR');
});
