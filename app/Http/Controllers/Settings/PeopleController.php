<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateUserRoleRequest;
use App\Http\Resources\PersonData;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PeopleController extends Controller
{
    public function index(Request $request): Response
    {
        $viewer = $request->user();
        abort_unless($viewer?->isAdmin(), 403);

        return Inertia::render('Settings/People', [
            'people' => User::query()
                ->orderBy('name')
                ->get()
                ->map(fn (User $user): array => PersonData::from($user, $viewer))
                ->all(),
        ]);
    }

    public function update(UpdateUserRoleRequest $request, User $user): RedirectResponse
    {
        $isAdmin = $request->string('role')->toString() === 'admin';
        $user->forceFill(['is_admin' => $isAdmin])->save();

        return back()->with('success', $isAdmin ? "Made {$user->name} an admin." : "{$user->name} is now a member.");
    }
}
