<?php

use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\Api\Cli\SessionFileController;
use App\Http\Controllers\Api\Cli\SshLookupController;
use App\Http\Controllers\Api\Cli\StackMessageController;
use App\Http\Controllers\Api\Cli\TeleportController;
use App\Http\Controllers\Api\Cli\ThreadLookupController;
use App\Http\Controllers\ArtifactController;
use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\Auth\LinearOAuthController;
use App\Http\Controllers\ChannelController;
use App\Http\Controllers\Cli\DeviceCodeController;
use App\Http\Controllers\Cli\DeviceController;
use App\Http\Controllers\Cli\DeviceTokenController;
use App\Http\Controllers\Cli\MeController;
use App\Http\Controllers\Cli\RepositoryResolveController;
use App\Http\Controllers\CostDashboardController;
use App\Http\Controllers\Deployments\AuthBounceController;
use App\Http\Controllers\Deployments\DeploymentActionController;
use App\Http\Controllers\Deployments\DeploymentController;
use App\Http\Controllers\Deployments\ShareLinkController;
use App\Http\Controllers\Devices\DeviceApprovalController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\Home\HomeController;
use App\Http\Controllers\InstallScriptController;
use App\Http\Controllers\Internal\DeploymentStatusController;
use App\Http\Controllers\Internal\DeploymentWakeController;
use App\Http\Controllers\Internal\PreviewAuthController;
use App\Http\Controllers\Internal\SessionStoreController;
use App\Http\Controllers\Internal\SshActivityController;
use App\Http\Controllers\Internal\SshAuthorizedKeysController;
use App\Http\Controllers\Internal\SshListenersController;
use App\Http\Controllers\Internal\SshTerminalCheckController;
use App\Http\Controllers\Internal\SshTerminalRedeemController;
use App\Http\Controllers\Internal\SshWakeController;
use App\Http\Controllers\Internal\ThreadPreviewHostStatusController;
use App\Http\Controllers\Internal\ThreadPreviewWakeController;
use App\Http\Controllers\MarketplaceController;
use App\Http\Controllers\ObservationController;
use App\Http\Controllers\PromptController;
use App\Http\Controllers\PromptPreviewController;
use App\Http\Controllers\PromptVersionController;
use App\Http\Controllers\PrReviews\PrReviewFeedbackController;
use App\Http\Controllers\PrReviews\PrReviewForPrController;
use App\Http\Controllers\Repositories\GitHubCiDetectController;
use App\Http\Controllers\Repositories\GitHubRepoSearchController;
use App\Http\Controllers\Repositories\ManifestController;
use App\Http\Controllers\Repositories\RepositoryActionController;
use App\Http\Controllers\Repositories\RepositoryClaudeAccountController;
use App\Http\Controllers\Repositories\RepositoryController;
use App\Http\Controllers\Repositories\RiskProfileController;
use App\Http\Controllers\Settings\McpLoginController;
use App\Http\Controllers\Settings\McpServerController;
use App\Http\Controllers\SkillController;
use App\Http\Controllers\SystemController;
use App\Http\Controllers\TaskAttachmentController;
use App\Http\Controllers\Tasks\CoOwnerDecisionController;
use App\Http\Controllers\Tasks\DismissSetupCardController;
use App\Http\Controllers\Tasks\EarlyWarningController;
use App\Http\Controllers\Tasks\PullRequestImageController;
use App\Http\Controllers\Tasks\QueuedMessageController;
use App\Http\Controllers\Tasks\RequestReReviewController;
use App\Http\Controllers\Tasks\StoreTaskController;
use App\Http\Controllers\Tasks\TaskActionController;
use App\Http\Controllers\Tasks\TaskClarificationAnswerController;
use App\Http\Controllers\Tasks\TaskController;
use App\Http\Controllers\Tasks\TaskListController;
use App\Http\Controllers\Tasks\TaskMessageController;
use App\Http\Controllers\Threads\ContinueTurnController;
use App\Http\Controllers\Threads\NewThreadController;
use App\Http\Controllers\Threads\Preview\ThreadPreviewHeartbeatController;
use App\Http\Controllers\Threads\Preview\ThreadPreviewRestoreController;
use App\Http\Controllers\Threads\Preview\ThreadPreviewStatusController;
use App\Http\Controllers\Threads\Preview\ThreadPreviewWarmController;
use App\Http\Controllers\Threads\PullRequests\AskToFixController;
use App\Http\Controllers\Threads\PullRequests\MergeOrderController;
use App\Http\Controllers\Threads\PullRequests\PullRequestsShowController;
use App\Http\Controllers\Threads\RepositoryAccessController;
use App\Http\Controllers\Threads\RepositorySetupStatusController;
use App\Http\Controllers\Threads\RestoreRetryController;
use App\Http\Controllers\Threads\Stack\ChildDiffController;
use App\Http\Controllers\Threads\Stack\ChildOverrideController;
use App\Http\Controllers\Threads\Stack\StackChildStoreController;
use App\Http\Controllers\Threads\Stack\StackLimitController;
use App\Http\Controllers\Threads\Stack\StackShowController;
use App\Http\Controllers\Threads\Stack\StackStopController;
use App\Http\Controllers\Threads\StoreThreadController;
use App\Http\Controllers\Threads\TerminalSessionController;
use App\Http\Controllers\Threads\ThreadController;
use App\Http\Controllers\Threads\ThreadDiffController;
use App\Http\Controllers\Threads\ThreadEnvironmentController;
use App\Http\Controllers\Threads\ThreadEventController;
use App\Http\Controllers\Threads\ThreadFileController;
use App\Http\Controllers\Threads\ThreadListController;
use App\Http\Controllers\Threads\ThreadMessageController;
use App\Http\Controllers\Threads\ThreadPullRequestController;
use App\Http\Controllers\Threads\ThreadRepositoryController;
use App\Http\Controllers\Threads\ThreadRepositoryProgressController;
use App\Http\Controllers\Threads\ThreadSeenController;
use App\Http\Controllers\Threads\ThreadShareController;
use App\Http\Controllers\Threads\ThreadTeleportRequestController;
use App\Http\Controllers\VideoThemeAssetController;
use App\Http\Middleware\CliProtocol;
use App\Models\User;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

Route::get('install', InstallScriptController::class)->name('install');

Route::get('letmein', function () {
    abort_unless(app()->environment('local'), 404);

    $user = User::query()->first() ?? User::factory()->create();
    auth()->login($user);

    return redirect()->route('home');
})->name('letmein');

Route::middleware('guest')->group(function () {
    Route::view('login', 'auth.login')->name('login');
    Route::get('auth/google', [GoogleAuthController::class, 'redirect'])->name('auth.google');
    Route::get('auth/google/callback', [GoogleAuthController::class, 'callback'])->name('auth.google.callback');
});

Route::middleware(['auth'])->group(function () {
    Route::get('tasks', TaskListController::class)->name('tasks');
    Route::post('tasks', StoreTaskController::class)->name('tasks.store');
    Route::post('tasks/setup-card/dismiss', DismissSetupCardController::class)->name('tasks.setup-card.dismiss');
    Route::get('tasks/{task}', [TaskController::class, 'show'])->name('tasks.show');
    Route::get('tasks/{task}/log-entries', [TaskController::class, 'logEntries'])->name('tasks.log-entries');
    Route::middleware('task.drive')->group(function (): void {
        Route::post('tasks/{task}/retry', [TaskActionController::class, 'retry'])->name('tasks.retry');
        Route::post('tasks/{task}/cancel', [TaskActionController::class, 'cancel'])->name('tasks.cancel');
        Route::post('tasks/{task}/rerun-review', [TaskActionController::class, 'rerunReview'])->name('tasks.rerun-review');
        Route::post('tasks/{task}/retry-render', [TaskActionController::class, 'retryRender'])->name('tasks.retry-render');
        Route::post('tasks/{task}/reroute', [TaskActionController::class, 'reroute'])->name('tasks.reroute');
        Route::post('tasks/{task}/co-owner/approve', [CoOwnerDecisionController::class, 'approve'])->name('tasks.co-owner.approve');
        Route::post('tasks/{task}/co-owner/request-changes', [CoOwnerDecisionController::class, 'requestChanges'])->name('tasks.co-owner.request-changes');
        Route::post('tasks/{task}/co-owner/reject', [CoOwnerDecisionController::class, 'reject'])->name('tasks.co-owner.reject');
        Route::post('tasks/{task}/co-owner/withdraw', [CoOwnerDecisionController::class, 'withdraw'])->name('tasks.co-owner.withdraw');
        Route::post('tasks/{task}/co-owner/check-access', [CoOwnerDecisionController::class, 'checkAccess'])->name('tasks.co-owner.check-access');
        Route::post('tasks/{task}/co-owner/tag', [EarlyWarningController::class, 'tag'])->name('tasks.co-owner.tag');
        Route::post('tasks/{task}/co-owner/decline', [EarlyWarningController::class, 'decline'])->name('tasks.co-owner.decline');
        Route::post('tasks/{task}/messages', [TaskMessageController::class, 'store'])->name('tasks.messages.store');
        Route::patch('tasks/{task}/queued-messages/{message}', [QueuedMessageController::class, 'update'])->whereNumber('message')->name('tasks.queued-messages.update');
        Route::delete('tasks/{task}/queued-messages/{message}', [QueuedMessageController::class, 'destroy'])->whereNumber('message')->name('tasks.queued-messages.destroy');
        Route::post('tasks/{task}/clarification-answers', TaskClarificationAnswerController::class)->name('tasks.clarification-answers.store');
        Route::post('tasks/{task}/re-request-review', RequestReReviewController::class)
            ->name('tasks.re-request-review');
    });
    Route::get('tasks/{task}/pr-images/{asset}', PullRequestImageController::class)
        ->whereUuid('asset')
        ->name('tasks.pr-images.show');
    Route::get('task-attachments/{attachment}', TaskAttachmentController::class)
        ->middleware('signed')
        ->name('task-attachments.show');
    Route::get('costs', CostDashboardController::class)->name('costs');
    Route::get('analytics', AnalyticsController::class)->name('analytics');

    Route::get('repos', [RepositoryController::class, 'index'])->name('repos');
    Route::get('repos/create', [RepositoryController::class, 'create'])->name('repos.create');
    Route::post('repos', [RepositoryController::class, 'store'])->name('repos.store');
    Route::get('repos/github-search', GitHubRepoSearchController::class)->name('repos.github-search');
    Route::get('repos/github-detect', GitHubCiDetectController::class)->name('repos.github-detect');
    Route::get('repos/{repository}/edit', [RepositoryController::class, 'edit'])
        ->name('repos.edit')
        ->where('repository', '.+');
    Route::get('repos/{repository}/risk-profile', RiskProfileController::class)
        ->name('repos.risk-profile.show')
        ->middleware('throttle:30,1')
        ->where('repository', '.+');
    Route::patch('repos/{repository}', [RepositoryController::class, 'update'])
        ->name('repos.update')
        ->where('repository', '.+');
    Route::delete('repos/{repository}', [RepositoryController::class, 'destroy'])
        ->name('repos.destroy')
        ->where('repository', '.+');
    Route::post('repos/{repository}/toggle-active', [RepositoryActionController::class, 'toggleActive'])
        ->name('repos.toggle-active')
        ->where('repository', '.+');
    Route::post('repos/{repository}/rerun-setup', [RepositoryActionController::class, 'rerunSetup'])
        ->name('repos.rerun-setup')
        ->where('repository', '.+');
    Route::post('repos/{repository}/config/refresh', [RepositoryActionController::class, 'refreshConfig'])
        ->name('repos.config.refresh')
        ->where('repository', '.+');
    Route::post('repos/{repository}/config/migrate', [RepositoryActionController::class, 'migrateConfig'])
        ->name('repos.config.migrate')
        ->where('repository', '.+');
    Route::post('repos/{repository}/review-open-prs', [RepositoryActionController::class, 'reviewOpenPrs'])
        ->name('repos.review-open-prs')
        ->where('repository', '.+');
    Route::post('repos/{repository}/risk-profile', [RepositoryActionController::class, 'riskProfile'])
        ->name('repos.risk-profile')
        ->where('repository', '.+');
    Route::post('repos/{repository}/rebuild-deployments', [RepositoryActionController::class, 'rebuildDeployments'])
        ->name('repos.rebuild-deployments')
        ->where('repository', '.+');
    Route::put('repos/{repository}/manifest', [ManifestController::class, 'update'])
        ->name('repos.manifest.update')
        ->where('repository', '.+');

    Route::get('observations', ObservationController::class)->name('observations');

    Route::get('health', [HealthController::class, 'index'])->name('health');
    Route::get('system/{path?}', SystemController::class)->where('path', '.*')->name('system');
    Route::post('health/refresh', [HealthController::class, 'refreshAll'])->name('health.refresh');
    Route::post('health/{check}/refresh', [HealthController::class, 'refreshOne'])->name('health.check.refresh');
    Route::get('channels', ChannelController::class)->name('channels');

    Route::get('skills', [SkillController::class, 'index'])->name('skills');
    Route::post('skills', [SkillController::class, 'store'])->name('skills.install');
    Route::patch('skills/{name}', [SkillController::class, 'update'])
        ->name('skills.update')
        ->where('name', '.+');
    Route::post('skills/{name}/update', [SkillController::class, 'upgrade'])
        ->name('skills.upgrade')
        ->where('name', '.+');
    Route::delete('skills/{name}', [SkillController::class, 'destroy'])
        ->name('skills.destroy')
        ->where('name', '.+');

    Route::post('marketplaces', [MarketplaceController::class, 'store'])->name('marketplaces.store');
    Route::post('marketplaces/refresh', [MarketplaceController::class, 'refresh'])->name('marketplaces.refresh');
    Route::delete('marketplaces/{name}', [MarketplaceController::class, 'destroy'])
        ->name('marketplaces.destroy')
        ->where('name', '.+');

    Route::get('mcp', [McpServerController::class, 'index'])->name('mcp');
    Route::post('mcp', [McpServerController::class, 'store'])->name('mcp.store');

    // Registered before the shorter mcp.destroy/logout routes below:
    // {name} is deliberately greedy (`.+`) so a colon-bearing plugin name
    // like "plugin:figma:figma" is captured whole, but that greediness
    // also lets it swallow a trailing "/login" or "/login/redirect"
    // segment. Laravel matches routes in registration order, so the
    // longer URIs must come first or every one of these would be
    // shadowed by mcp.destroy/mcp.logout.
    Route::post('mcp/{name}/login/redirect', [McpLoginController::class, 'redirect'])->where('name', '.+')->name('mcp.login.redirect');
    Route::post('mcp/{name}/login', [McpLoginController::class, 'start'])->where('name', '.+')->name('mcp.login.start');
    Route::delete('mcp/{name}/login', [McpLoginController::class, 'cancel'])->where('name', '.+')->name('mcp.login.cancel');

    Route::post('mcp/{name}/logout', [McpServerController::class, 'logout'])->where('name', '.+')->name('mcp.logout');
    Route::delete('mcp/{name}', [McpServerController::class, 'destroy'])->where('name', '.+')->name('mcp.destroy');

    Route::get('prompts', [PromptController::class, 'index'])->name('prompts');
    Route::get('prompts/{slug}', [PromptController::class, 'show'])->name('prompts.show');
    Route::put('prompts/{slug}', [PromptController::class, 'update'])->name('prompts.update');
    Route::delete('prompts/{slug}', [PromptController::class, 'reset'])->name('prompts.reset');
    Route::post('prompts/{slug}/preview', PromptPreviewController::class)->name('prompts.preview');
    Route::get('prompts/{slug}/versions/{version}', [PromptVersionController::class, 'show'])
        ->name('prompts.versions.show')
        ->where('version', '[0-9]+');
    Route::get('pr-reviews', PrReviewFeedbackController::class)->name('pr-reviews');
    Route::get('pr-reviews/for/{repoSlug}/{prNumber}', [PrReviewForPrController::class, 'show'])
        ->name('pr-reviews.for-pr')
        ->where('repoSlug', '.+')
        ->where('prNumber', '[0-9]+');
    Route::post('pr-reviews/for/{repoSlug}/{prNumber}/rerun', [PrReviewForPrController::class, 'rerun'])
        ->name('pr-reviews.for-pr.rerun')
        ->where('repoSlug', '.+')
        ->where('prNumber', '[0-9]+');

    Route::get('deployments', [DeploymentController::class, 'index'])->name('deployments');
    Route::get('deployments/{deployment}', [DeploymentController::class, 'show'])
        ->name('deployments.show');
    Route::patch('deployments/{deployment}/hibernation', [DeploymentActionController::class, 'updateHibernation'])
        ->name('deployments.hibernation.update');
    Route::post('deployments/{deployment}/rebuild', [DeploymentActionController::class, 'rebuild'])
        ->name('deployments.rebuild');
    Route::delete('deployments/{deployment}', [DeploymentActionController::class, 'destroy'])
        ->name('deployments.destroy');
    Route::post('deployments/{deployment}/share', [ShareLinkController::class, 'store'])
        ->name('deployments.share.store');
    Route::delete('deployments/{deployment}/share', [ShareLinkController::class, 'destroy'])
        ->name('deployments.share.destroy');

    Route::get('auth/linear', [LinearOAuthController::class, 'redirect'])->name('auth.linear.redirect');
    Route::get('auth/linear/callback', [LinearOAuthController::class, 'callback'])->name('auth.linear.callback');

    // Threads and the thread page.
    Route::post('threads/{thread}/messages', [ThreadMessageController::class, 'store'])
        ->name('threads.messages.store');
    Route::post('threads/{thread}/stop', [ThreadMessageController::class, 'stop'])
        ->name('threads.stop');
    Route::post('threads/{thread}/continue', ContinueTurnController::class)
        ->name('threads.continue');
    Route::post('threads/{thread}/restore/retry', RestoreRetryController::class)
        ->name('threads.restore.retry');
    Route::get('threads/new', NewThreadController::class)->name('threads.new');
    Route::post('threads', StoreThreadController::class)->name('threads.store');
    Route::get('threads/{thread}', [ThreadController::class, 'show'])
        ->name('threads.show');

    // Claude accounts.
    Route::put('repos/{repository}/claude-account', [RepositoryClaudeAccountController::class, 'updateMine'])->name('repos.claude-account.update');
    Route::put('repos/{repository}/unattended-claude-account', [RepositoryClaudeAccountController::class, 'updateUnattended'])->name('repos.unattended-claude-account.update');

    // Shell and thread list.
    Route::get('threads/list', ThreadListController::class)->name('threads.list');
    Route::post('threads/{thread}/seen', ThreadSeenController::class)
        ->name('threads.seen');

    // Changes.
    Route::get('threads/{thread}/diff', [ThreadDiffController::class, 'show'])
        ->name('threads.diff.show');
    Route::get('threads/{thread}/diff/files/{file}', [ThreadDiffController::class, 'showFile'])
        ->name('threads.diff.files.show')
        ->where(['file' => '[0-9]+']);
    Route::get('threads/{thread}/environment', [ThreadEnvironmentController::class, 'show'])
        ->name('threads.environment.show');
    Route::post('threads/{thread}/pull-request', [ThreadPullRequestController::class, 'store'])
        ->name('threads.pull-request.store');

    // Realtime.
    Route::get('threads/{thread}/events', [ThreadEventController::class, 'index'])
        ->name('threads.events.index');
    Route::get('threads/{thread}/events/{sequence}', [ThreadEventController::class, 'show'])
        ->name('threads.events.show')
        ->where(['sequence' => '[0-9]+']);

    // Visibility and sharing.
    Route::get('threads/{thread}/share', [ThreadShareController::class, 'show'])
        ->name('threads.share.show');
    Route::put('threads/{thread}/share', [ThreadShareController::class, 'update'])
        ->name('threads.share.update');

    // Device approval and teleport requests.
    Route::get('device', [DeviceApprovalController::class, 'show'])->name('device.show');
    Route::post('device/approve', [DeviceApprovalController::class, 'approve'])->name('device.approve');
    Route::post('device/deny', [DeviceApprovalController::class, 'deny'])->name('device.deny');

    Route::post('threads/{thread}/teleport-request', [ThreadTeleportRequestController::class, 'store'])
        ->name('threads.teleport-request.store');
    Route::delete('threads/{thread}/teleport-request', [ThreadTeleportRequestController::class, 'destroy'])
        ->name('threads.teleport-request.destroy');

    // Stacks.
    Route::post('threads/{thread}/stack/take-over', [ChildOverrideController::class, 'takeOver'])
        ->name('threads.stack.take-over');
    Route::post('threads/{thread}/stack/split', [ChildOverrideController::class, 'split'])
        ->name('threads.stack.split');
    Route::post('threads/{thread}/stack/drop', [ChildOverrideController::class, 'drop'])
        ->name('threads.stack.drop');

    Route::patch('threads/{thread}/stack/limit', StackLimitController::class)
        ->name('threads.stack.limit');

    Route::post('threads/{thread}/stack/stop', StackStopController::class)
        ->name('threads.stack.stop');

    Route::get('threads/{thread}/stack', StackShowController::class)
        ->name('threads.stack.show');
    Route::post('threads/{thread}/stack/children', StackChildStoreController::class)
        ->name('threads.stack.children.store');
    Route::get('threads/{thread}/stack/children/{child}/diff', ChildDiffController::class)
        ->name('threads.stack.children.diff');

    // Previews.
    Route::get('threads/{thread}/preview/status', ThreadPreviewStatusController::class)
        ->name('threads.preview.status');
    Route::post('threads/{thread}/preview/heartbeat', ThreadPreviewHeartbeatController::class)
        ->name('threads.preview.heartbeat');
    Route::post('threads/{thread}/preview/warm', ThreadPreviewWarmController::class)
        ->name('threads.preview.warm');
    Route::post('threads/{thread}/preview/restore', ThreadPreviewRestoreController::class)
        ->name('threads.preview.restore');

    // Files.
    Route::get('threads/{thread}/files', [ThreadFileController::class, 'tree'])
        ->name('threads.files.tree');
    Route::get('threads/{thread}/files/content', [ThreadFileController::class, 'show'])
        ->name('threads.files.show');
    Route::put('threads/{thread}/files/content', [ThreadFileController::class, 'update'])
        ->name('threads.files.update');

    // Terminal sessions (ssh).
    Route::post('threads/{thread}/terminal-sessions', [TerminalSessionController::class, 'store'])
        ->name('threads.terminal-sessions.store');

    // Multi-repo and pull requests.
    Route::get('threads/new/repository-access', RepositoryAccessController::class)
        ->middleware('throttle:30,1')
        ->name('threads.repository-access');

    Route::get('threads/new/repository-setup', RepositorySetupStatusController::class)
        ->middleware('throttle:60,1')
        ->name('threads.repository-setup');

    Route::get('threads/{thread}/repositories', ThreadRepositoryProgressController::class)
        ->name('threads.repositories.progress');

    Route::patch('threads/{thread}/repositories/{repository}', [ThreadRepositoryController::class, 'update'])
        ->name('threads.repositories.update')
        ->where(['repository' => '[0-9]+'])
        ->scopeBindings();

    Route::post('threads/{thread}/repositories/{repository}/retry', [ThreadRepositoryController::class, 'retry'])
        ->name('threads.repositories.retry')
        ->where(['repository' => '[0-9]+'])
        ->scopeBindings();

    Route::get('threads/{thread}/pull-requests', PullRequestsShowController::class)
        ->name('threads.pull-requests.show');
    Route::put('threads/{thread}/pull-requests/order', MergeOrderController::class)
        ->name('threads.pull-requests.order');
    Route::post('threads/{thread}/pull-requests/{repository}/fix', AskToFixController::class)
        ->name('threads.pull-requests.fix')
        ->where(['repository' => '[0-9]+']);
});

// Device flow and CLI API: no session, authenticated by signed codes or device tokens.
Route::prefix('api/cli/v1')->middleware('cli.protocol')->withoutMiddleware(CliProtocol::WITHOUT_WEB_STATE)->group(function (): void {
    Route::post('device/code', DeviceCodeController::class)->middleware('throttle:cli-device-code')->name('cli.device.code');
    Route::post('device/token', DeviceTokenController::class)->name('cli.device.token');

    Route::middleware('auth.device')->group(function (): void {
        Route::get('me', MeController::class)->name('cli.me');
        Route::delete('device', [DeviceController::class, 'destroy'])->name('cli.device.destroy');
        Route::post('repos/resolve', RepositoryResolveController::class)->name('cli.repos.resolve');
    });
});

Route::prefix('api/cli/v1')
    ->middleware(['cli.protocol', 'auth.device'])
    ->withoutMiddleware(CliProtocol::WITHOUT_WEB_STATE)
    ->group(function (): void {
        Route::get('threads/by-session/{sessionId}', [ThreadLookupController::class, 'bySession']);
        Route::get('threads/{thread}', [ThreadLookupController::class, 'show']);

        Route::post('teleports', [TeleportController::class, 'begin']);
        Route::get('teleports/{teleport}', [TeleportController::class, 'show']);
        Route::post('teleports/{teleport}/complete', [TeleportController::class, 'complete']);
        Route::post('teleports/{teleport}/cancel', [TeleportController::class, 'cancel']);

        Route::get('threads/{thread}/stack-messages', [StackMessageController::class, 'index']);
        Route::post('threads/{thread}/stack-messages/ack', [StackMessageController::class, 'ack']);
        Route::get('threads/{thread}/ssh', [SshLookupController::class, 'show']);

        Route::prefix('threads/{thread}/session')
            ->middleware('throttle:cli-session')
            ->group(function (): void {
                Route::get('length', [SessionFileController::class, 'length']);
                Route::post('append', [SessionFileController::class, 'append']);
                Route::post('checkpoint', [SessionFileController::class, 'checkpoint']);
                Route::put('file', [SessionFileController::class, 'file']);
                Route::get('files', [SessionFileController::class, 'files']);
                Route::get('read', [SessionFileController::class, 'read']);
                Route::get('tree', [SessionFileController::class, 'tree']);
            });
    });

// Host-only endpoints, served to the ingress and the broker only.
Route::middleware(['restrict-to-ingress'])->group(function (): void {
    Route::middleware('session-store-token')
        ->prefix('/internal/session-store/{thread}')
        ->where(['thread' => '[0-9]+'])
        ->name('session-store.')
        ->group(function (): void {
            Route::post('append', [SessionStoreController::class, 'append'])->name('append');
            Route::get('length', [SessionStoreController::class, 'length'])->name('length');
            Route::post('checkpoint', [SessionStoreController::class, 'checkpoint'])->name('checkpoint');
            Route::put('file', [SessionStoreController::class, 'file'])->name('file');
            Route::get('read', [SessionStoreController::class, 'read'])->name('read');
            Route::get('files', [SessionStoreController::class, 'files'])->name('files');
            Route::get('tree', [SessionStoreController::class, 'tree'])->name('tree');
        });

    Route::get('/internal/threads/preview/wake', ThreadPreviewWakeController::class)->name('threads.preview.wake');
    Route::get('/internal/threads/preview/status', ThreadPreviewHostStatusController::class)->name('threads.preview.host-status');

    Route::middleware('ssh-internal-token')
        ->prefix('/internal/ssh')
        ->name('internal.ssh.')
        ->group(function (): void {
            Route::post('authorized-keys', SshAuthorizedKeysController::class)->name('authorized-keys');
            Route::get('listeners', SshListenersController::class)->name('listeners');
            Route::post('wake', SshWakeController::class)->name('wake');
            Route::post('terminal/redeem', SshTerminalRedeemController::class)->name('terminal-redeem');
            Route::post('terminal/check', SshTerminalCheckController::class)->name('terminal-check');
            Route::post('activity', SshActivityController::class)->name('activity');
        });
});

Route::get('artifacts/public/{token}', [ArtifactController::class, 'publicImage'])
    ->name('artifacts.public')
    ->where('token', '[0-9A-Za-z]{26}');

Route::get('video-theme/logo', [VideoThemeAssetController::class, 'logo'])
    ->name('video-theme.logo');

Route::get('video-theme/sample', [VideoThemeAssetController::class, 'sample'])
    ->middleware('auth')
    ->name('video-theme.sample');

Route::get('artifacts/{task}/viewer/{filename}', [ArtifactController::class, 'viewer'])
    ->name('artifacts.viewer')
    ->middleware('auth')
    ->where('filename', '.*');

Route::get('artifacts/{task}/{filename}', [ArtifactController::class, 'show'])
    ->name('artifacts.show')
    ->where('filename', '.*');

// Wake allows anonymous access with a valid share token OR cookie;
// otherwise falls through to requiring an authenticated session.
Route::middleware(['restrict-to-ingress'])
    ->get('/internal/deployments/wake', DeploymentWakeController::class)
    ->name('deployments.wake');

// Ticket exchange for a preview host. Caddy rewrites
// https://<preview>/_yak/auth here; it sets the host-only preview cookie.
Route::middleware(['restrict-to-ingress'])
    ->get('/internal/deployments/preview-auth', PreviewAuthController::class)
    ->name('deployments.preview-auth');

// Public bounce endpoint on the dashboard. The wake endpoint redirects
// unauthenticated preview visits here; this controller either forwards
// already-authed users back to the preview or kicks off the OAuth flow
// with the preview URL stored as url.intended. Signature prevents an
// attacker from crafting standalone phishing links to the endpoint —
// only the wake endpoint (which validates the `to` hostname against
// an existing deployment) can mint a valid URL.
Route::get('/deployments/auth-bounce', AuthBounceController::class)
    ->middleware('signed')
    ->name('deployments.auth-bounce');

// Status is used by the shim JS on an already-trusted page; keep `auth`.
Route::middleware(['restrict-to-ingress', 'auth'])
    ->get('/internal/deployments/status', DeploymentStatusController::class)
    ->name('deployments.status');

require __DIR__ . '/settings.php';
