<?php

use App\Http\Controllers\Settings\GitHubAccountController;
use App\Models\User;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as SocialiteUser;

function fakeGitHubUser(int $id = 4242, string $login = 'mhansen'): void
{
    Socialite::fake('github', (new SocialiteUser)->map(['id' => $id, 'nickname' => $login, 'name' => 'Someone', 'token' => 'gho_secret']));
}

it('builds the connect url with an optional return path', function () {
    expect(GitHubAccountController::connectUrl(null))->toBe(route('settings.github.connect'))
        ->and(GitHubAccountController::connectUrl('/tasks/12'))->toBe(route('settings.github.connect', ['return' => '/tasks/12']));
});

it('links the GitHub account and returns to the profile', function () {
    $user = User::factory()->create();
    fakeGitHubUser();
    $this->actingAs($user);

    $this->get(route('settings.github.connect'))->assertRedirect();
    $this->get(route('settings.github.callback'))
        ->assertRedirect(route('profile.edit'))
        ->assertSessionHas('success', 'GitHub connected as mhansen.');

    $user->refresh();
    expect($user->github_id)->toBe(4242)
        ->and($user->github_login)->toBe('mhansen')
        ->and($user->github_linked_at)->not->toBeNull()
        ->and($user->hasLinkedGitHub())->toBeTrue();
});

it('returns to a task page after linking', function () {
    fakeGitHubUser();
    $this->actingAs(User::factory()->create());

    $this->get(route('settings.github.connect', ['return' => '/tasks/12']));
    $this->get(route('settings.github.callback'))->assertRedirect('/tasks/12');
});

it('pulls the return path from the session once it is used', function () {
    fakeGitHubUser();
    $this->actingAs(User::factory()->create());

    $this->get(route('settings.github.connect', ['return' => '/tasks/12']));
    $this->get(route('settings.github.callback'))->assertRedirect('/tasks/12');
    $this->get(route('settings.github.callback'))->assertRedirect(route('profile.edit'));
});

it('ignores an unsafe return url', function (string $return) {
    fakeGitHubUser();
    $this->actingAs(User::factory()->create());

    $this->get(route('settings.github.connect', ['return' => $return]));
    $this->get(route('settings.github.callback'))->assertRedirect(route('profile.edit'));
})->with([
    '//evil.example',
    '/\\evil.example',
    '/%09/evil.example',
    '/%2F/evil.example',
    '/%2F%2Fevil.example',
    'https://evil.example',
    'tasks/12',
    "/tasks/\n12",
]);

it('shows the error when GitHub returns access_denied', function () {
    $user = User::factory()->create();
    fakeGitHubUser();
    $this->actingAs($user);

    $this->get(route('settings.github.callback', ['error' => 'access_denied']))
        ->assertRedirect(route('profile.edit'))
        ->assertSessionHasErrors(['github' => 'error=access_denied']);

    expect($user->refresh()->hasLinkedGitHub())->toBeFalse();
});

it('refuses a GitHub account linked to another user', function () {
    $owner = User::factory()->withGitHub(4242, 'mhansen')->create(['name' => 'Cory Mawhorter']);
    $user = User::factory()->create();
    fakeGitHubUser();
    $this->actingAs($user);

    $this->get(route('settings.github.callback'))
        ->assertRedirect(route('profile.edit'))
        ->assertSessionHasErrors(['github_taken' => 'Cory Mawhorter', 'github_taken_login' => 'mhansen']);

    expect($owner->refresh()->github_id)->toBe(4242)
        ->and($user->refresh()->hasLinkedGitHub())->toBeFalse();
});

it('stores only the GitHub id, login and linked time', function () {
    $user = User::factory()->create();
    fakeGitHubUser();
    $this->actingAs($user);
    $before = $user->fresh()->getAttributes();

    $this->get(route('settings.github.callback'));

    $changed = array_keys(array_diff_assoc($user->fresh()->getAttributes(), $before));
    expect(array_diff($changed, ['updated_at']))->toEqualCanonicalizing(['github_id', 'github_login', 'github_linked_at']);
});

it('disconnects the GitHub account', function () {
    $user = User::factory()->withGitHub()->create();
    $this->actingAs($user);

    $this->delete(route('settings.github.disconnect'))
        ->assertRedirect(route('profile.edit'))
        ->assertSessionHas('success', 'GitHub disconnected.');

    $user->refresh();
    expect($user->github_id)->toBeNull()
        ->and($user->github_login)->toBeNull()
        ->and($user->github_linked_at)->toBeNull();
});

it('requires authentication', function () {
    $this->get(route('settings.github.connect'))->assertRedirect(route('login'));
    $this->delete(route('settings.github.disconnect'))->assertRedirect(route('login'));
});

it('passes the linked account to the profile page', function () {
    $this->actingAs(User::factory()->withGitHub()->create());

    $this->get(route('profile.edit'))->assertInertia(fn ($page) => $page
        ->where('profile.github.login', 'mhansen')
        ->has('profile.github.linkedAt'));

    $this->actingAs(User::factory()->create());
    $this->get(route('profile.edit'))->assertInertia(fn ($page) => $page->where('profile.github', null));
});

it('reports a taken account when another user links it between the check and the save', function () {
    $user = User::factory()->create();
    User::factory()->create(['name' => 'Cory Mawhorter']);
    fakeGitHubUser();
    $this->actingAs($user);

    // The racing user links the same GitHub id just before this user's save runs.
    User::saving(function (User $saving) use ($user) {
        if ($saving->is($user) && $saving->github_id !== null) {
            User::query()->where('name', 'Cory Mawhorter')->update(['github_id' => 4242, 'github_login' => 'mhansen']);
        }
    });

    $this->get(route('settings.github.callback'))
        ->assertRedirect(route('profile.edit'))
        ->assertSessionHasErrors(['github_taken' => 'Cory Mawhorter', 'github_taken_login' => 'mhansen']);

    expect($user->fresh()->hasLinkedGitHub())->toBeFalse();
});

it('shows an error when GitHub sign-in state is invalid', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    Socialite::shouldReceive('driver->user')->andThrow(new InvalidStateException);

    $this->get(route('settings.github.callback'))
        ->assertRedirect(route('profile.edit'))
        ->assertSessionHasErrors(['github' => 'error=invalid_state']);

    expect($user->fresh()->hasLinkedGitHub())->toBeFalse();
});
