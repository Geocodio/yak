<?php

use App\Models\Prompt;
use App\Models\PromptVersion;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('prompts index redirects to the first prompt', function () {
    $this->get(route('prompts'))
        ->assertRedirect(route('prompts.show', 'system'));
});

test('show renders the sidebar groups and the selected prompt', function () {
    $this->get(route('prompts.show', 'tasks-setup'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Prompts/Index')
            ->where('prompt.slug', 'tasks-setup')
            ->where('prompt.label', 'Setup')
            ->where('prompt.customized', false)
            ->where('prompt.content', fn (string $content) => str_contains($content, '{{ $repoName }}'))
            ->has('prompts', 2)
            ->where('prompts.0.group', 'High-touch')
            ->has('prompts.0.items'));
});

test('show 404s for an unknown slug', function () {
    $this->get(route('prompts.show', 'nope'))->assertNotFound();
});

test('saving creates a baseline version then the user edit on first save', function () {
    $prompt = Prompt::where('slug', 'tasks-setup')->firstOrFail();

    $this->put(route('prompts.update', 'tasks-setup'), [
        'content' => 'My custom setup for {{ $repoName }}.',
    ])->assertRedirect(route('prompts.show', 'tasks-setup'));

    $prompt->refresh();

    expect($prompt->is_customized)->toBeTrue();
    expect($prompt->content)->toBe('My custom setup for {{ $repoName }}.');
    expect(PromptVersion::where('prompt_id', $prompt->id)->count())->toBe(2);

    $versions = PromptVersion::where('prompt_id', $prompt->id)->orderBy('version')->get();
    expect($versions[0]->content)->toContain('{{ $repoName }}');
    expect($versions[1]->content)->toBe('My custom setup for {{ $repoName }}.');
});

test('subsequent saves only add one version per save', function () {
    $this->put(route('prompts.update', 'tasks-setup'), ['content' => 'Edit 1 {{ $repoName }}']);
    $this->put(route('prompts.update', 'tasks-setup'), ['content' => 'Edit 2 {{ $repoName }}']);

    $prompt = Prompt::where('slug', 'tasks-setup')->firstOrFail();
    expect(PromptVersion::where('prompt_id', $prompt->id)->count())->toBe(3);
});

test('save rejects content with disallowed directives', function () {
    $this->put(route('prompts.update', 'tasks-setup'), [
        'content' => 'Hello @include("evil")',
    ])->assertSessionHasErrors('content');

    $prompt = Prompt::where('slug', 'tasks-setup')->firstOrFail();
    expect($prompt->is_customized)->toBeFalse();
});

test('save rejects content that fails to compile', function () {
    $this->put(route('prompts.update', 'tasks-setup'), [
        'content' => '@if($repoName',
    ])->assertSessionHasErrors('content');
});

test('save rejects content that throws during dry-render', function () {
    $this->put(route('prompts.update', 'tasks-setup'), [
        'content' => '{{ $repoName->method() }}',
    ])->assertSessionHasErrors('content');
});

test('reset to default clears is_customized and reloads the file contents', function () {
    $this->put(route('prompts.update', 'tasks-setup'), ['content' => 'My custom setup.']);

    $this->delete(route('prompts.reset', 'tasks-setup'))
        ->assertRedirect(route('prompts.show', 'tasks-setup'));

    $prompt = Prompt::where('slug', 'tasks-setup')->firstOrFail();
    expect($prompt->is_customized)->toBeFalse();
    expect($prompt->content)->toBeNull();
});

test('version show returns the version content as json', function () {
    $this->put(route('prompts.update', 'tasks-setup'), ['content' => 'Edit 1 {{ $repoName }}']);

    $prompt = Prompt::where('slug', 'tasks-setup')->firstOrFail();
    $baseline = PromptVersion::where('prompt_id', $prompt->id)->orderBy('version')->first();

    $this->getJson(route('prompts.versions.show', ['tasks-setup', $baseline->id]))
        ->assertOk()
        ->assertJson(['content' => $baseline->content]);
});

test('version show 404s for a version belonging to a different prompt', function () {
    $this->put(route('prompts.update', 'tasks-setup'), ['content' => 'Edit 1 {{ $repoName }}']);
    $prompt = Prompt::where('slug', 'tasks-setup')->firstOrFail();
    $baseline = PromptVersion::where('prompt_id', $prompt->id)->orderBy('version')->first();

    $this->getJson(route('prompts.versions.show', ['system', $baseline->id]))
        ->assertNotFound();
});
