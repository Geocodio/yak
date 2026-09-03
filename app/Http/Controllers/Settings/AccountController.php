<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\DeleteAccountRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

class AccountController extends Controller
{
    public function destroy(DeleteAccountRequest $request): RedirectResponse
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        Auth::guard('web')->logout();

        Session::invalidate();
        Session::regenerateToken();

        $user->delete();

        return redirect('/');
    }
}
