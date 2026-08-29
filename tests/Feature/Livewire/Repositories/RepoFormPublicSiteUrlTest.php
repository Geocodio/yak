<?php

use App\Livewire\Repos\RepoForm;
use App\Models\Repository;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

it('loads the existing public site url', function (): void {
    $repo = Repository::factory()->create(['public_site_url' => 'https://www.example.com']);

    Livewire::test(RepoForm::class, ['repository' => $repo])
        ->assertSet('public_site_url', 'https://www.example.com');
});

it('saves a public site url', function (): void {
    $repo = Repository::factory()->create(['public_site_url' => null]);

    Livewire::test(RepoForm::class, ['repository' => $repo])
        ->set('public_site_url', 'https://www.example.com')
        ->call('save')
        ->assertHasNoErrors();

    expect($repo->fresh()->public_site_url)->toBe('https://www.example.com');
});

it('stores null when the field is cleared', function (): void {
    $repo = Repository::factory()->create(['public_site_url' => 'https://www.example.com']);

    Livewire::test(RepoForm::class, ['repository' => $repo])
        ->set('public_site_url', '')
        ->call('save')
        ->assertHasNoErrors();

    expect($repo->fresh()->public_site_url)->toBeNull();
});

it('rejects a value that is not a url', function (): void {
    $repo = Repository::factory()->create();

    Livewire::test(RepoForm::class, ['repository' => $repo])
        ->set('public_site_url', 'not a url')
        ->call('save')
        ->assertHasErrors(['public_site_url']);
});
