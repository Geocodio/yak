<?php

namespace App\Http\Controllers\Repositories;

use App\Channels\GitHub\AppService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Repositories\UpdateApprovalSettingsRequest;
use App\Http\Resources\ApprovalSettingsPageData;
use App\Models\Repository;
use App\Services\RepositoryConfig;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ApprovalSettingsController extends Controller
{
    public function show(Repository $repository): Response
    {
        $snapshot = app(RepositoryConfig::class)->snapshot($repository);

        return Inertia::render('Repositories/Approvals', [
            'repository' => ApprovalSettingsPageData::repository($repository),
            'approval' => ApprovalSettingsPageData::approval($repository),
            'concerns' => ApprovalSettingsPageData::concerns($repository, $snapshot),
            'shadow' => ApprovalSettingsPageData::shadow($repository),
            'github' => Inertia::defer(fn (): array => ApprovalSettingsPageData::github($repository), 'approvals-github'),
        ]);
    }

    public function update(UpdateApprovalSettingsRequest $request, Repository $repository): RedirectResponse
    {
        $level = $request->integer('level');
        $mode = $level === 1 ? 'shadow' : $request->string('mode')->toString();

        if ($mode === 'enforce' && $repository->approvalMode() !== 'enforce') {
            $this->ensureRequiredChecks($repository);
        }

        $repository->setApproval($level, $mode, $request->user());

        $label = match (true) {
            $level === 1 => 'review only',
            $mode === 'enforce' => 'armed',
            default => 'shadow',
        };

        return back()->with('success', "DEFCON {$level} " . ApprovalSettingsPageData::CODENAMES[$level] . ", {$label} is live.");
    }

    private function ensureRequiredChecks(Repository $repository): void
    {
        try {
            $hasChecks = app(AppService::class)->hasRequiredStatusChecks(
                (int) config('yak.channels.github.installation_id'),
                $repository->github_full_name,
                $repository->default_branch,
            );
        } catch (\Throwable $exception) {
            report($exception);

            throw ValidationException::withMessages(['mode' => 'Could not check branch protection on GitHub. Try again.']);
        }

        if (! $hasChecks) {
            throw ValidationException::withMessages(['mode' => "Arming is locked: {$repository->default_branch} has no required status checks."]);
        }
    }
}
