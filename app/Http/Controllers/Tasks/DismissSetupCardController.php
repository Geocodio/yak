<?php

namespace App\Http\Controllers\Tasks;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DismissSetupCardController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user !== null) {
            $user->forceFill(['has_seen_setup_card_at' => now()])->save();
        }

        return redirect()->back();
    }
}
