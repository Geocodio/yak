<?php

use App\Models\Prompt;
use App\Models\User;
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

it('registers and resolves an editable repository risk profile prompt', function () {
    expect(PromptDefinitions::has('tasks-risk-profile'))->toBeTrue();
    $resolver = app(PromptResolver::class);
    expect($resolver->render('tasks-risk-profile'))->toContain('areas');
    Prompt::updateOrCreate(['slug' => 'tasks-risk-profile'], [
        'content' => 'Custom repository risk research instructions.', 'is_customized' => true,
    ]);
    expect($resolver->render('tasks-risk-profile'))->toBe('Custom repository risk research instructions.');
});

it('saves and resets the risk prompt through the versioned editor', function () {
    $this->actingAs(User::factory()->create());
    $this->put(route('prompts.update', 'tasks-risk-profile'), ['content' => 'Custom risk profile prompt.'])
        ->assertSessionHas('success');
    $prompt = Prompt::where('slug', 'tasks-risk-profile')->firstOrFail();
    expect($prompt->versions()->count())->toBe(2)
        ->and(app(PromptResolver::class)->render('tasks-risk-profile'))->toBe('Custom risk profile prompt.');
    $this->delete(route('prompts.reset', 'tasks-risk-profile'))->assertSessionHas('success');
    expect(app(PromptResolver::class)->render('tasks-risk-profile'))->toContain('areas');
});
