<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateProfileRequest;
use App\Models\User;
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

    public function resendVerification(Request $request): RedirectResponse
    {
        $user = $this->authenticatedUser();

        if (! $user instanceof MustVerifyEmail || $user->hasVerifiedEmail()) {
            return redirect()->intended(route('tasks', absolute: false));
        }

        $user->sendEmailVerificationNotification();

        return redirect()->route('profile.edit')->with('success', 'A new verification link has been sent to your email address.');
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
