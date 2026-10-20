<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\User;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\InvalidStateException;

class GitHubAccountController extends Controller
{
    private const RETURN_SESSION_KEY = 'github_link_return';

    public static function connectUrl(?string $returnTo = null): string
    {
        return route('settings.github.connect', $returnTo === null ? [] : ['return' => $returnTo]);
    }

    public function connect(Request $request): RedirectResponse
    {
        $returnTo = $request->query('return');

        $request->session()->put(
            self::RETURN_SESSION_KEY,
            is_string($returnTo) && $this->isLocalPath($returnTo) ? $returnTo : route('profile.edit'),
        );

        /** @var AbstractProvider $driver */
        $driver = Socialite::driver('github');

        return $driver->setScopes([])->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        $destination = $this->pullReturnPath($request);

        if ($request->filled('error')) {
            return redirect($destination)->withErrors(['github' => 'error=' . $request->string('error')]);
        }

        try {
            $githubUser = Socialite::driver('github')->user();
        } catch (InvalidStateException|ClientException) {
            return redirect($destination)->withErrors(['github' => 'error=invalid_state']);
        }

        $user = $this->authenticatedUser();

        $existingOwner = $this->owner($githubUser->getId(), $user);

        if ($existingOwner !== null) {
            return $this->takenResponse($destination, $existingOwner);
        }

        try {
            $user->forceFill([
                'github_id' => $githubUser->getId(),
                'github_login' => $githubUser->getNickname(),
                'github_linked_at' => now(),
            ])->save();
        } catch (UniqueConstraintViolationException $exception) {
            $owner = $this->owner($githubUser->getId(), $user);

            if ($owner === null) {
                throw $exception;
            }

            return $this->takenResponse($destination, $owner);
        }

        return redirect($destination)->with('success', "GitHub connected as {$user->github_login}.");
    }

    public function disconnect(): RedirectResponse
    {
        $this->authenticatedUser()->forceFill([
            'github_id' => null,
            'github_login' => null,
            'github_linked_at' => null,
        ])->save();

        return redirect()->route('profile.edit')->with('success', 'GitHub disconnected.');
    }

    private function owner(mixed $githubId, User $user): ?User
    {
        return User::query()->where('github_id', $githubId)->where('id', '!=', $user->id)->first();
    }

    private function takenResponse(string $destination, User $owner): RedirectResponse
    {
        return redirect($destination)->withErrors([
            'github_taken' => $owner->name,
            'github_taken_login' => (string) $owner->github_login,
        ]);
    }

    private function pullReturnPath(Request $request): string
    {
        $stored = $request->session()->pull(self::RETURN_SESSION_KEY);

        return is_string($stored) && ($this->isLocalPath($stored) || $stored === route('profile.edit'))
            ? $stored
            : route('profile.edit');
    }

    /**
     * A single-slash path with no backslash, whitespace or control characters,
     * before or after percent-decoding, and no scheme or host.
     */
    private function isLocalPath(string $path): bool
    {
        $decoded = rawurldecode($path);

        foreach ([$path, $decoded] as $candidate) {
            if (! str_starts_with($candidate, '/') || str_starts_with($candidate, '//') || preg_match('/[\s\x00-\x1f\x7f\\\\]/', $candidate) === 1) {
                return false;
            }
        }

        $parts = parse_url($path);

        return $parts !== false && ! isset($parts['scheme']) && ! isset($parts['host']);
    }

    private function authenticatedUser(): User
    {
        $user = Auth::user();

        abort_unless($user instanceof User, 401);

        return $user;
    }
}
