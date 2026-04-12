<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;

class GoogleAuthController extends Controller
{
    public function redirect(): RedirectResponse
    {
        /** @var RedirectResponse */
        return Socialite::driver('google')->redirect();
    }

    public function callback(): RedirectResponse
    {
        $googleUser = Socialite::driver('google')->user();

        $allowedDomains = $this->allowedDomains();

        $email = (string) $googleUser->getEmail();
        $atPos = mb_strpos($email, '@');
        $emailDomain = $atPos !== false ? mb_substr($email, $atPos + 1) : '';

        if (! in_array($emailDomain, $allowedDomains, true)) {
            return redirect()->route('login')->withErrors([
                'email' => 'Your email domain is not authorized to access this application.',
            ]);
        }

        $user = User::updateOrCreate(
            ['email' => $googleUser->getEmail()],
            [
                'name' => $googleUser->getName(),
                'email_verified_at' => now(),
            ],
        );

        Auth::login($user, remember: true);

        return redirect()->intended(route('tasks'));
    }

    /**
     * @return list<string>
     */
    private function allowedDomains(): array
    {
        $domains = config('services.google.allowed_domains');

        if (! is_string($domains) || $domains === '') {
            abort(500, 'GOOGLE_OAUTH_ALLOWED_DOMAINS must be configured.');
        }

        return array_map('trim', explode(',', $domains));
    }
}
