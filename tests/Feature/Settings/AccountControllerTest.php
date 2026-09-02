<?php

use App\Models\User;

test('deletes the account with the correct password', function () {
    $user = User::factory()->create(['password' => 'password']);
    $this->actingAs($user);

    $this->delete(route('account.destroy'), ['password' => 'password'])
        ->assertRedirect('/');

    expect(User::query()->find($user->id))->toBeNull();
    $this->assertGuest();
});

test('requires the correct password to delete the account', function () {
    $user = User::factory()->create(['password' => 'password']);
    $this->actingAs($user);

    $this->delete(route('account.destroy'), ['password' => 'wrong-password'])
        ->assertSessionHasErrors('password');

    expect(User::query()->find($user->id))->not->toBeNull();
    $this->assertAuthenticated();
});
