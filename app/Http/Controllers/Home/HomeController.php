<?php

namespace App\Http\Controllers\Home;

use App\Http\Controllers\Controller;
use App\Http\Resources\NewThreadData;
use App\Http\Resources\WelcomeData;
use App\Models\Thread;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Guests get the landing page. Signed-in people land on their most recently
 * active visible thread, or on the Welcome page when they can see none.
 */
class HomeController extends Controller
{
    public function __invoke(Request $request): View|RedirectResponse|Response
    {
        $user = $request->user();

        if ($user === null) {
            return view('welcome');
        }

        $thread = Thread::query()
            ->orderByRaw('COALESCE(last_activity_at, created_at) desc')
            ->orderByDesc('id')
            ->first();

        if ($thread !== null) {
            return redirect()->route('threads.show', $thread);
        }

        return Inertia::render('Home/Welcome', [
            'welcome' => WelcomeData::forUser($user),
            ...NewThreadData::for($user),
        ]);
    }
}
