<?php

namespace App\Http\Controllers\Repositories;

use App\Http\Controllers\Controller;
use App\Http\Requests\Repositories\SaveRepositoryClaudeAccountRequest;
use App\Models\Repository;
use App\Models\UserRepositorySetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

class RepositoryClaudeAccountController extends Controller
{
    public function updateMine(SaveRepositoryClaudeAccountRequest $request, Repository $repository): RedirectResponse
    {
        $accountId = $request->validated('claude_account_id');
        $scope = ['user_id' => Auth::id(), 'repo' => $repository->slug];

        if ($accountId === null) {
            UserRepositorySetting::query()->where($scope)->delete();
        } else {
            UserRepositorySetting::query()->updateOrCreate($scope, [
                'claude_account_id' => $accountId,
                'model' => $request->validated('model'),
            ]);
        }

        return back()->with('success', 'Your Claude account for this repository was saved.');
    }

    public function updateUnattended(SaveRepositoryClaudeAccountRequest $request, Repository $repository): RedirectResponse
    {
        $repository->update(['unattended_claude_account_id' => $request->validated('claude_account_id')]);

        return back()->with('success', 'Unattended account saved.');
    }
}
