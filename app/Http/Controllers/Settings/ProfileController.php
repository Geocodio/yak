<?php

namespace App\Http\Controllers\Settings;

use App\Channels\ChannelRegistry;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateDirectMessagesRequest;
use App\Http\Requests\Settings\UpdateProfileRequest;
use App\Models\User;
use App\Models\UserEmailAddress;
use Carbon\Carbon;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    public function edit(): Response
    {
        $user = $this->authenticatedUser();

        return Inertia::render('Settings/Profile', [
            'profile' => fn () => [
                'name' => $user->name,
                'email' => $user->email,
                'hasUnverifiedEmail' => $this->hasUnverifiedEmail($user),
                'directMessagesEnabled' => $user->direct_messages_enabled,
                'github' => $user->hasLinkedGitHub() ? [
                    'login' => $user->github_login,
                    'linkedAt' => Carbon::parse($user->github_linked_at)->toIso8601String(),
                ] : null,
                'emailToYak' => $this->emailToYak($user),
            ],
        ]);
    }

    public function update(UpdateProfileRequest $request): RedirectResponse
    {
        $user = $this->authenticatedUser();

        $user->fill($request->validated());

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        return redirect()->route('profile.edit')->with('success', 'Profile updated.');
    }

    public function updateDirectMessages(UpdateDirectMessagesRequest $request): RedirectResponse
    {
        $isEnabled = $request->boolean('directMessagesEnabled');

        $this->authenticatedUser()->update(['direct_messages_enabled' => $isEnabled]);

        return redirect()->route('profile.edit')->with('success', 'Slack direct messages turned ' . ($isEnabled ? 'on' : 'off') . '.');
    }

    public function resendVerification(Request $request): RedirectResponse
    {
        $user = $this->authenticatedUser();

        if (! $user instanceof MustVerifyEmail || $user->hasVerifiedEmail()) {
            return redirect()->intended(route('home', absolute: false));
        }

        $user->sendEmailVerificationNotification();

        return redirect()->route('profile.edit')->with('success', 'A new verification link has been sent to your email address.');
    }

    /**
     * @return array{inboundAddress: string, addresses: list<array{id: int, email: string, confirmed: bool}>}|null
     */
    private function emailToYak(User $user): ?array
    {
        if (! (app(ChannelRegistry::class)->for('email')?->enabled() ?? false)) {
            return null;
        }

        return [
            'inboundAddress' => (string) config('yak.channels.email.inbound_address'),
            'addresses' => array_values($user->emailAddresses()->orderBy('id')->get()
                ->map(fn (UserEmailAddress $address): array => [
                    'id' => $address->id,
                    'email' => $address->email,
                    'confirmed' => $address->isConfirmed(),
                ])->all()),
        ];
    }

    private function hasUnverifiedEmail(User $user): bool
    {
        return $user instanceof MustVerifyEmail && ! $user->hasVerifiedEmail();
    }

    private function authenticatedUser(): User
    {
        $user = Auth::user();

        abort_unless($user instanceof User, 401);

        return $user;
    }
}
