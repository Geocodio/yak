<?php

use App\Models\User;
use App\Models\UserEmailAddress;

it('finds a user by their sign-in address regardless of case and spaces', function () {
    $user = User::factory()->create(['email' => 'mathias@geocod.io']);

    expect(User::findBySenderEmail('  Mathias@GEOCOD.io '))->id->toBe($user->id);
});

it('finds a user by a confirmed extra address', function () {
    $address = UserEmailAddress::factory()->confirmed()->create(['email' => 'mhansen@hey.com']);

    expect(User::findBySenderEmail('MHansen@HEY.com '))->id->toBe($address->user_id);
});

it('ignores unconfirmed extra addresses', function () {
    UserEmailAddress::factory()->unconfirmed()->create(['email' => 'typo@geocdio.io']);

    expect(User::findBySenderEmail('typo@geocdio.io'))->toBeNull();
});

it('returns null for a blank sender', function () {
    expect(User::findBySenderEmail('  '))->toBeNull();
});

it('stores addresses lowercased and trimmed', function () {
    expect(UserEmailAddress::factory()->create(['email' => ' Foo@Bar.COM '])->email)->toBe('foo@bar.com');
});

it('lists a user\'s extra addresses', function () {
    $address = UserEmailAddress::factory()->create();

    expect($address->user->emailAddresses->pluck('id')->all())->toBe([$address->id])
        ->and($address->isConfirmed())->toBeFalse();
});
