<?php

namespace App\Http\Controllers\Repositories;

use App\Http\Controllers\Controller;
use App\Http\Requests\Repositories\SaveRepositoryClaudeAccountRequest;
use App\Models\Repository;
use App\Models\UserRepositorySetting;
use Illuminate\Http\RedirectResponse;

class RepositoryClaudeAccountController extends Controller
{
    public function updateMine(SaveRepositoryClaudeAccountRequest $request, Repository $repository): RedirectResponse
    {
        $criteria = ['user_id' => $request->user()?->id, 'repo' => $repository->slug];
        $accountId = $request->integer('claude_account_id') ?: null;

        if ($accountId === null) {
            UserRepositorySetting::query()->where($criteria)->delete();
        } else {
            UserRepositorySetting::query()->updateOrCreate($criteria, [
                'claude_account_id' => $accountId,
                'model' => $request->input('model'),
            ]);
        }

        return back()->with('success', 'Saved.');
    }

    public function updateUnattended(SaveRepositoryClaudeAccountRequest $request, Repository $repository): RedirectResponse
    {
        $repository->forceFill(['unattended_claude_account_id' => $request->integer('claude_account_id') ?: null])->save();

        return back()->with('success', 'Saved.');
    }
}
