<?php

namespace App\Http\Controllers\Home;

use App\Http\Controllers\Controller;
use App\Http\Resources\NewThreadData;
use App\Http\Resources\WelcomeData;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Guests get the landing page. Signed-in people always land on the Welcome page:
 * the setup checklist until a repository and a Claude account exist, then the
 * start screen. Their threads are one click away in the sidebar.
 */
class HomeController extends Controller
{
    public function __invoke(Request $request): View|RedirectResponse|Response
    {
        $user = $request->user();

        if ($user === null) {
            return view('welcome');
        }

        return Inertia::render('Home/Welcome', [
            'welcome' => WelcomeData::forUser($user),
            ...NewThreadData::for($user),
        ]);
    }
}
