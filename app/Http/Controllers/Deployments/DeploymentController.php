<?php

namespace App\Http\Controllers\Deployments;

use App\Enums\DeploymentStatus;
use App\Http\Controllers\Controller;
use App\Models\BranchDeployment;
use App\Models\DeploymentLog;
use App\Support\DeploymentPresentation;
use App\Support\HibernationDuration;
use App\Support\ReleaseBranch;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response;

class DeploymentController extends Controller
{
    public function index(Request $request): Response
    {
        $status = $request->string('status', 'active')->toString();

        return Inertia::render('Deployments/Index', [
            'deployments' => fn () => $this->paginatedDeployments($status),
            'filters' => [
                'status' => $status,
            ],
        ]);
    }

    public function show(Request $request, BranchDeployment $deployment): Response
    {
        $deployment->load('repository');

        return Inertia::render('Deployments/Show', [
            'deployment' => fn () => $this->deploymentData($deployment),
            'hibernation' => fn () => $this->hibernationData($deployment),
            'manifest' => fn () => $this->manifestData($deployment),
            'shareLink' => fn () => $this->shareLinkData($deployment),
            'mintedUrl' => fn () => $request->session()->get('mintedUrl'),
            'logs' => fn () => $this->recentLogs($deployment),
            'pollInterval' => fn () => $this->pollInterval($deployment),
        ]);
    }

    /**
     * @return LengthAwarePaginator<int, array{id: int, repoSlug: string, branch: string, status: string, statusLabel: string, tone: string, hostname: string, lastAccessedAgo: ?string, longLived: bool, hibernatesAfter: string}>
     */
    private function paginatedDeployments(string $status): LengthAwarePaginator
    {
        $query = BranchDeployment::query()->with('repository');

        if ($status === 'active') {
            $query->whereIn('status', [
                DeploymentStatus::Pending->value,
                DeploymentStatus::Starting->value,
                DeploymentStatus::Running->value,
                DeploymentStatus::Hibernated->value,
                DeploymentStatus::Failed->value,
            ]);
        } elseif ($status !== 'all') {
            $query->where('status', $status);
        }

        $deployments = $query->orderByDesc('last_accessed_at')->paginate(25);

        return $deployments->through(fn (BranchDeployment $deployment): array => [
            'id' => $deployment->id,
            'repoSlug' => $deployment->repository->slug,
            'branch' => $deployment->branch_name,
            'status' => $deployment->status->value,
            'statusLabel' => DeploymentPresentation::label($deployment->status),
            'tone' => DeploymentPresentation::tone($deployment->status),
            'hostname' => $deployment->hostname,
            'lastAccessedAgo' => $deployment->last_accessed_at?->diffForHumans(),
            'longLived' => (bool) $deployment->long_lived,
            'hibernatesAfter' => HibernationDuration::humanize($deployment->effectiveIdleMinutes()),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function deploymentData(BranchDeployment $deployment): array
    {
        return [
            'id' => $deployment->id,
            'hostname' => $deployment->hostname,
            'url' => "https://{$deployment->hostname}",
            'repoSlug' => $deployment->repository->slug,
            'branch' => $deployment->branch_name,
            'status' => $deployment->status->value,
            'statusLabel' => DeploymentPresentation::label($deployment->status),
            'tone' => DeploymentPresentation::tone($deployment->status),
            'commit' => $deployment->current_commit_sha !== null ? substr($deployment->current_commit_sha, 0, 10) : null,
            'templateVersion' => $deployment->template_version,
            'repoTemplateVersion' => $deployment->repository->current_template_version,
            'lastAccessedAgo' => $deployment->last_accessed_at?->diffForHumans(),
            'failure' => $deployment->status === DeploymentStatus::Failed ? $deployment->failure_reason : null,
        ];
    }

    /**
     * @return array{longLived: bool, autoLongLived: bool, timeout: string}
     */
    private function hibernationData(BranchDeployment $deployment): array
    {
        return [
            'longLived' => (bool) $deployment->long_lived,
            'autoLongLived' => ReleaseBranch::matches($deployment->branch_name),
            'timeout' => HibernationDuration::toShorthand($deployment->effectiveIdleMinutes()),
        ];
    }

    /**
     * @return array{port: int, healthProbePath: string, coldStart: string, checkoutRefresh: string, wakeTimeoutSeconds: int}
     */
    private function manifestData(BranchDeployment $deployment): array
    {
        $manifest = $deployment->repository->preview_manifest ?? [];

        return [
            'port' => (int) ($manifest['port'] ?? config('yak.deployments.default_port')),
            'healthProbePath' => (string) ($manifest['health_probe_path'] ?? '/'),
            'coldStart' => (string) ($manifest['cold_start'] ?? ''),
            'checkoutRefresh' => (string) ($manifest['checkout_refresh'] ?? ''),
            'wakeTimeoutSeconds' => (int) ($manifest['wake_timeout_seconds'] ?? 120),
        ];
    }

    /**
     * @return array{active: bool, expiresAgo: ?string}|null
     */
    private function shareLinkData(BranchDeployment $deployment): ?array
    {
        if ($deployment->public_share_token_hash === null) {
            return null;
        }

        return [
            'active' => true,
            'expiresAgo' => $deployment->public_share_expires_at?->diffForHumans(),
        ];
    }

    /**
     * @return array<int, array{at: string, phase: ?string, message: string, output: string, error: bool}>
     */
    private function recentLogs(BranchDeployment $deployment): array
    {
        return $deployment->logs()
            ->with('chunks')
            ->latest('id')
            ->limit(200)
            ->get()
            ->reverse()
            ->values()
            ->map(fn (DeploymentLog $log): array => [
                'at' => $log->created_at?->format('H:i:s') ?? '',
                'phase' => $log->phase,
                'message' => $log->message,
                'output' => $log->output(),
                'error' => $log->level === 'error',
            ])
            ->all();
    }

    /**
     * Faster polling while the deployment is actively transitioning so the
     * activity log feels responsive; back off on settled states.
     */
    private function pollInterval(BranchDeployment $deployment): int
    {
        return match ($deployment->status) {
            DeploymentStatus::Pending,
            DeploymentStatus::Starting,
            DeploymentStatus::Destroying => 2000,
            default => 15000,
        };
    }
}
