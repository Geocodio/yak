<?php

use App\Livewire\PromptEditor;
use App\Models\Prompt;
use App\Models\PromptVersion;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('it is accessible at /prompts route', function () {
    $this->get(route('prompts'))->assertOk();
});

test('selecting a prompt loads its default content', function () {
    Livewire::test(PromptEditor::class)
        ->call('selectPrompt', 'tasks-setup')
        ->assertSet('selectedSlug', 'tasks-setup')
        ->assertSet('content', fn (string $c) => str_contains($c, '{{ $repoName }}'));
});

test('saving creates a baseline version then the user edit on first save', function () {
    $prompt = Prompt::where('slug', 'tasks-setup')->firstOrFail();

    Livewire::test(PromptEditor::class)
        ->call('selectPrompt', 'tasks-setup')
        ->set('content', 'My custom setup for {{ $repoName }}.')
        ->call('save')
        ->assertSet('saveError', false);

    $prompt->refresh();

    expect($prompt->is_customized)->toBeTrue();
    expect($prompt->content)->toBe('My custom setup for {{ $repoName }}.');
    expect(PromptVersion::where('prompt_id', $prompt->id)->count())->toBe(2);

    $versions = PromptVersion::where('prompt_id', $prompt->id)->orderBy('version')->get();
    expect($versions[0]->content)->toContain('{{ $repoName }}');
    expect($versions[1]->content)->toBe('My custom setup for {{ $repoName }}.');
});

test('subsequent saves only add one version per save', function () {
    Livewire::test(PromptEditor::class)
        ->call('selectPrompt', 'tasks-setup')
        ->set('content', 'Edit 1 {{ $repoName }}')
        ->call('save')
        ->set('content', 'Edit 2 {{ $repoName }}')
        ->call('save');

    $prompt = Prompt::where('slug', 'tasks-setup')->firstOrFail();
    expect(PromptVersion::where('prompt_id', $prompt->id)->count())->toBe(3);
});

test('loading a prior version populates the editor content', function () {
    $component = Livewire::test(PromptEditor::class)
        ->call('selectPrompt', 'tasks-setup')
        ->set('content', 'Edit 1 {{ $repoName }}')
        ->call('save');

    $prompt = Prompt::where('slug', 'tasks-setup')->firstOrFail();
    $baseline = PromptVersion::where('prompt_id', $prompt->id)->orderBy('version')->first();

    $component
        ->call('loadVersion', $baseline->id)
        ->assertSet('content', fn (string $c) => str_contains($c, '{{ $repoName }}'))
        ->assertSet('selectedVersionId', $baseline->id);
});

test('reset to default clears is_customized and reloads the file contents', function () {
    Livewire::test(PromptEditor::class)
        ->call('selectPrompt', 'tasks-setup')
        ->set('content', 'My custom setup.')
        ->call('save')
        ->call('resetToDefault')
        ->assertSet('saveError', false);

    $prompt = Prompt::where('slug', 'tasks-setup')->firstOrFail();
    expect($prompt->is_customized)->toBeFalse();
    expect($prompt->content)->toBeNull();
});

test('save rejects content with disallowed directives', function () {
    Livewire::test(PromptEditor::class)
        ->call('selectPrompt', 'tasks-setup')
        ->set('content', 'Hello @include("evil")')
        ->call('save')
        ->assertSet('saveError', true);

    $prompt = Prompt::where('slug', 'tasks-setup')->firstOrFail();
    expect($prompt->is_customized)->toBeFalse();
});

test('save rejects content that fails to compile', function () {
    Livewire::test(PromptEditor::class)
        ->call('selectPrompt', 'tasks-setup')
        ->set('content', '@if($repoName')
        ->call('save')
        ->assertSet('saveError', true);
});

test('save rejects content that throws during dry-render', function () {
    Livewire::test(PromptEditor::class)
        ->call('selectPrompt', 'tasks-setup')
        ->set('content', '{{ $repoName->method() }}')
        ->call('save')
        ->assertSet('saveError', true);
});

test('preview renders successfully when content matches the fixture', function () {
    Livewire::test(PromptEditor::class)
        ->call('selectPrompt', 'tasks-setup')
        ->set('content', 'Setup for {{ $repoName }}.')
        ->assertSee('Setup for acme/billing.');
});

test('preview shows error panel when content has a runtime error', function () {
    Livewire::test(PromptEditor::class)
        ->call('selectPrompt', 'tasks-setup')
        ->set('content', '{{ $repoName->method() }}')
        ->assertSeeHtml('data-test="prompt-preview-error"');
});

test('unknownVariables reports variables not in the definition', function () {
    Livewire::test(PromptEditor::class)
        ->call('selectPrompt', 'tasks-setup')
        ->set('content', 'Hello {{ $ghost }}')
        ->assertSee('Unknown variables in prompt');
});
