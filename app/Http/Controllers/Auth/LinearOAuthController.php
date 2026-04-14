<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\LinearOAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

class LinearOAuthController extends Controller
{
    private const SESSION_STATE_KEY = 'linear_oauth_state';

    public function redirect(Request $request, LinearOAuthService $service): RedirectResponse
    {
        if (! config('yak.channels.linear.oauth_client_id')) {
            abort(503, 'Linear OAuth client is not configured.');
        }

        $state = Str::random(40);
        $request->session()->put(self::SESSION_STATE_KEY, $state);

        return redirect()->away($service->authorizeUrl($state));
    }

    public function callback(Request $request, LinearOAuthService $service): RedirectResponse
    {
        $expectedState = (string) $request->session()->pull(self::SESSION_STATE_KEY, '');
        $providedState = (string) $request->query('state', '');

        if ($expectedState === '' || ! hash_equals($expectedState, $providedState)) {
            abort(403, 'Linear OAuth state mismatch.');
        }

        if ($error = $request->query('error')) {
            return redirect()->route('settings.linear')
                ->with('linear_oauth_error', 'Linear authorization failed: ' . $error);
        }

        $code = (string) $request->query('code', '');
        if ($code === '') {
            return redirect()->route('settings.linear')
                ->with('linear_oauth_error', 'Linear returned no authorization code.');
        }

        try {
            $connection = $service->exchangeCode($code, $request->user()?->id);
        } catch (Throwable $e) {
            report($e);

            return redirect()->route('settings.linear')
                ->with('linear_oauth_error', 'Could not complete Linear connection: ' . $e->getMessage());
        }

        return redirect()->route('settings.linear')
            ->with('linear_oauth_success', "Connected to Linear workspace “{$connection->workspace_name}”.");
    }
}
