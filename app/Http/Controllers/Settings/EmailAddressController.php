<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StoreEmailAddressRequest;
use App\Mail\ConfirmEmailAddressMail;
use App\Models\User;
use App\Models\UserEmailAddress;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Extra sender addresses for the email channel. A new address counts only
 * after its owner opens the signed link Yak emails to it and confirms while
 * signed in, so a typo or someone else's address never becomes a sender.
 */
class EmailAddressController extends Controller
{
    public function store(StoreEmailAddressRequest $request): RedirectResponse
    {
        $user = $this->authenticatedUser($request);

        // Each new address sends an email to an inbox the user names, so
        // cap how often one user can make Yak do that.
        abort_if(RateLimiter::tooManyAttempts("email-address-store:{$user->id}", 5), 429);
        RateLimiter::hit("email-address-store:{$user->id}", 3600);

        // Saved only if its confirmation email is queued, so a failure never
        // leaves an address the person was told nothing about.
        $emailAddress = DB::transaction(function () use ($user, $request): UserEmailAddress {
            /** @var UserEmailAddress $emailAddress */
            $emailAddress = $user->emailAddresses()->create(['email' => $request->validated('email')]);
            $this->sendConfirmation($emailAddress);

            return $emailAddress;
        });

        return redirect()->route('profile.edit')->with('success', "Confirmation sent to {$emailAddress->email}.");
    }

    public function resend(Request $request, UserEmailAddress $emailAddress): RedirectResponse
    {
        $this->authorizeOwner($request, $emailAddress);

        if ($emailAddress->isConfirmed()) {
            return redirect()->route('profile.edit');
        }

        $sent = RateLimiter::attempt("email-address-resend:{$emailAddress->id}", 1, fn () => $this->sendConfirmation($emailAddress), 60);

        abort_unless($sent, 429);

        return redirect()->route('profile.edit')->with('success', "Confirmation sent to {$emailAddress->email}.");
    }

    public function destroy(Request $request, UserEmailAddress $emailAddress): RedirectResponse
    {
        $this->authorizeOwner($request, $emailAddress);

        $emailAddress->delete();

        return redirect()->route('profile.edit')->with('success', "Removed {$emailAddress->email}.");
    }

    public function show(Request $request, UserEmailAddress $emailAddress): Response
    {
        $this->authorizeOwner($request, $emailAddress);

        return Inertia::render('Settings/ConfirmEmailAddress', [
            'emailAddress' => [
                'email' => $emailAddress->email,
                'confirmed' => $emailAddress->isConfirmed(),
            ],
            'confirmUrl' => $request->fullUrl(),
        ]);
    }

    public function confirm(Request $request, UserEmailAddress $emailAddress): RedirectResponse
    {
        $this->authorizeOwner($request, $emailAddress);

        $isSomeonesSignInAddress = User::query()->whereRaw('lower(email) = ?', [$emailAddress->email])->exists();
        abort_if($isSomeonesSignInAddress, 409, 'This address already belongs to a Yak account.');

        if (! $emailAddress->isConfirmed()) {
            $emailAddress->update(['confirmed_at' => now()]);
        }

        return redirect()->route('profile.edit')->with('success', "{$emailAddress->email} can now send email to Yak.");
    }

    private function sendConfirmation(UserEmailAddress $emailAddress): void
    {
        $confirmationUrl = URL::temporarySignedRoute('email-addresses.confirm.show', now()->addDay(), ['emailAddress' => $emailAddress->id]);

        Mail::to($emailAddress->email)->queue(new ConfirmEmailAddressMail($emailAddress, $confirmationUrl));

        $emailAddress->update(['confirmation_sent_at' => now()]);
    }

    private function authorizeOwner(Request $request, UserEmailAddress $emailAddress): void
    {
        abort_unless($emailAddress->user_id === $this->authenticatedUser($request)->id, 403);
    }

    private function authenticatedUser(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        return $user;
    }
}
