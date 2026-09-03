<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Jobs\McpLoginJob;
use App\Support\McpLoginSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class McpLoginController extends Controller
{
    public function start(string $name): RedirectResponse
    {
        $existing = McpLoginSession::find($name);

        if ($existing !== null && in_array($existing->status, McpLoginSession::ACTIVE_STATUSES, true)) {
            return back()->with('error', "A login for {$name} is already in progress.");
        }

        McpLoginSession::start($name);

        McpLoginJob::dispatch($name);

        return back();
    }

    public function redirect(Request $request, string $name): RedirectResponse
    {
        $validated = $request->validate([
            'redirectUrl' => ['required', 'url', 'max:4000'],
        ]);

        $redirectUrl = (string) $validated['redirectUrl'];

        if (! str_starts_with($redirectUrl, 'http://localhost') && ! str_starts_with($redirectUrl, 'http://127.0.0.1')) {
            return back()->with('error', 'That does not look like the redirect URL from the login page — it should start with http://localhost or http://127.0.0.1.');
        }

        $session = McpLoginSession::find($name);

        if ($session === null || $session->status !== 'awaiting_redirect') {
            return back()->with('error', "There is no login in progress for {$name}.");
        }

        $session->redirectUrl = $redirectUrl;
        $session->status = 'finishing';
        $session->save();

        return back();
    }

    public function cancel(string $name): RedirectResponse
    {
        $session = McpLoginSession::find($name);

        if ($session !== null) {
            $session->status = 'cancelled';
            $session->save();
        }

        return back();
    }
}
