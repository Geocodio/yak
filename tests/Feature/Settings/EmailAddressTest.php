<?php

use App\Mail\ConfirmEmailAddressMail;
use App\Models\User;
use App\Models\UserEmailAddress;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia;

function confirmationUrl(UserEmailAddress $address, int $minutes = 1440): string
{
    return URL::temporarySignedRoute('email-addresses.confirm.show', now()->addMinutes($minutes), ['emailAddress' => $address->id]);
}

it('adds an unconfirmed address and emails a confirmation link', function () {
    Mail::fake();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('profile.email-addresses.store'), ['email' => ' MHansen@hey.com '])
        ->assertRedirect(route('profile.edit'));

    $address = $user->emailAddresses()->sole();
    expect($address->email)->toBe('mhansen@hey.com')
        ->and($address->confirmed_at)->toBeNull()
        ->and($address->confirmation_sent_at)->not->toBeNull();
    Mail::assertQueued(ConfirmEmailAddressMail::class, fn (ConfirmEmailAddressMail $mail) => $mail->hasTo('mhansen@hey.com'));
});

it('rejects an address that is a sign-in address or another user\'s confirmed address', function (string $email) {
    Mail::fake();
    User::factory()->create(['email' => 'taken@geocod.io']);
    UserEmailAddress::factory()->confirmed()->create(['email' => 'claimed@hey.com']);

    $this->actingAs(User::factory()->create())
        ->post(route('profile.email-addresses.store'), ['email' => $email])
        ->assertSessionHasErrors('email');

    Mail::assertNothingQueued();
})->with(['Taken@geocod.io', 'CLAIMED@hey.com', 'not-an-email']);

it('saves no address when the confirmation email cannot be queued', function () {
    Mail::shouldReceive('to')->andThrow(new RuntimeException('MISCONF Redis is unable to persist to disk'));

    $this->actingAs(User::factory()->create())
        ->post(route('profile.email-addresses.store'), ['email' => 'mhansen@hey.com'])
        ->assertServerError();

    expect(UserEmailAddress::query()->count())->toBe(0);
});

it('rejects an address another user has pending instead of failing on the unique index', function () {
    UserEmailAddress::factory()->unconfirmed()->create(['email' => 'pending@hey.com']);

    $this->actingAs(User::factory()->create())
        ->post(route('profile.email-addresses.store'), ['email' => 'pending@hey.com'])
        ->assertSessionHasErrors('email');
});

it('limits how many addresses one user can add per hour', function () {
    Mail::fake();
    $user = User::factory()->create();

    foreach (range(1, 5) as $number) {
        $this->actingAs($user)->post(route('profile.email-addresses.store'), ['email' => "address{$number}@hey.com"])->assertRedirect();
    }

    $this->actingAs($user)->post(route('profile.email-addresses.store'), ['email' => 'address6@hey.com'])->assertTooManyRequests();

    Mail::assertQueuedCount(5);
});

it('refuses to confirm an address that has since become someone\'s sign-in address', function () {
    $address = UserEmailAddress::factory()->create(['email' => 'shared@geocod.io']);
    User::factory()->create(['email' => 'Shared@geocod.io']);

    $this->actingAs($address->user)->post(confirmationUrl($address))->assertStatus(409);

    expect($address->fresh()->confirmed_at)->toBeNull();
});

it('rejects an address the user already added', function () {
    $address = UserEmailAddress::factory()->create(['email' => 'mine@hey.com']);

    $this->actingAs($address->user)
        ->post(route('profile.email-addresses.store'), ['email' => 'mine@hey.com'])
        ->assertSessionHasErrors('email');
});

it('shows a confirm page on GET without confirming', function () {
    $address = UserEmailAddress::factory()->create();

    $this->actingAs($address->user)
        ->get(confirmationUrl($address))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Settings/ConfirmEmailAddress')
            ->where('emailAddress.email', $address->email));

    expect($address->fresh()->confirmed_at)->toBeNull();
});

it('confirms on POST through the signed URL', function () {
    $address = UserEmailAddress::factory()->create();
    $url = confirmationUrl($address);

    $this->actingAs($address->user)->post($url)->assertRedirect(route('profile.edit'));

    expect($address->fresh()->confirmed_at)->not->toBeNull();
});

it('refuses an expired link', function () {
    $address = UserEmailAddress::factory()->create();
    $url = confirmationUrl($address);

    $this->travel(25)->hours();

    $this->actingAs($address->user)->post($url)->assertForbidden();
    expect($address->fresh()->confirmed_at)->toBeNull();
});

it('refuses a tampered link', function () {
    $address = UserEmailAddress::factory()->create();

    $this->actingAs($address->user)->post(confirmationUrl($address) . 'x')->assertForbidden();
});

it('refuses a signed-in user who does not own the address', function () {
    $address = UserEmailAddress::factory()->create();

    $this->actingAs(User::factory()->create())->get(confirmationUrl($address))->assertForbidden();
    $this->actingAs(User::factory()->create())->post(confirmationUrl($address))->assertForbidden();
    expect($address->fresh()->confirmed_at)->toBeNull();
});

it('sends a guest to sign in before confirming', function () {
    $address = UserEmailAddress::factory()->create();

    $this->get(confirmationUrl($address))->assertRedirect();
    expect($address->fresh()->confirmed_at)->toBeNull();
});

it('resends a confirmation for an unconfirmed address and throttles to once a minute', function () {
    Mail::fake();
    $address = UserEmailAddress::factory()->create();

    $this->actingAs($address->user)->post(route('profile.email-addresses.resend', $address))->assertRedirect(route('profile.edit'));
    $this->actingAs($address->user)->post(route('profile.email-addresses.resend', $address))->assertTooManyRequests();

    Mail::assertQueuedCount(1);
});

it('does not resend for a confirmed address', function () {
    Mail::fake();
    $address = UserEmailAddress::factory()->confirmed()->create();

    $this->actingAs($address->user)->post(route('profile.email-addresses.resend', $address))->assertRedirect(route('profile.edit'));

    Mail::assertNothingQueued();
});

it('removes an address the user owns and refuses others', function () {
    $address = UserEmailAddress::factory()->create();

    $this->actingAs(User::factory()->create())->delete(route('profile.email-addresses.destroy', $address))->assertForbidden();
    $this->actingAs($address->user)->delete(route('profile.email-addresses.destroy', $address))->assertRedirect(route('profile.edit'));

    expect(UserEmailAddress::query()->count())->toBe(0);
});

it('renders the confirmation email with the owner name, button, and expiry copy', function () {
    $address = UserEmailAddress::factory()->create();
    $mail = new ConfirmEmailAddressMail($address, 'https://example.test/confirm');

    $mail->assertHasSubject('Confirm this address for Yak');
    $mail->assertSeeInHtml('Confirm address');
    $mail->assertSeeInHtml('https://example.test/confirm');
    $mail->assertSeeInHtml('expires in 24 hours');
    $mail->assertSeeInHtml($address->user->name);
    $mail->assertSeeInText('https://example.test/confirm');
});

it('keeps the signed link intact in the plain-text part', function () {
    $address = UserEmailAddress::factory()->create();
    $url = 'https://example.test/confirm?expires=1&signature=abc';

    (new ConfirmEmailAddressMail($address, $url))
        ->assertSeeInText($url)
        ->assertDontSeeInText('&amp;');
});
