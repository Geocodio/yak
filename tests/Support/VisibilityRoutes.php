<?php

namespace Tests\Support;

use App\Models\Artifact;
use App\Models\BranchDeployment;
use App\Models\PrReview;
use App\Models\TaskAttachment;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\YakTask;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;

/**
 * Every route is classified here, so a new route cannot ship without someone
 * deciding what it reveals about a private thread. The coverage test fails
 * on any route that is in none of the four groups.
 */
class VisibilityRoutes
{
    /**
     * Routes whose parameters bind a thread, task, run, deployment, attachment,
     * artifact or PR review. A hidden id must answer exactly like `missingUri`.
     * `ownerStatus` is the status the owner gets when it is not a plain
     * success (the control must still prove the owner is not turned away).
     *
     * @return array<string, array{method: string, uri: string, payload?: array<string, mixed>, missingUri: string, ownerStatus?: int}>
     */
    public static function probes(VisibilityWorld $world): array
    {
        $thread = $world->thread->id;
        $task = $world->task->id;
        $deployment = $world->deployment->id;
        $missing = $world->missingId;

        $probes = [
            'threads.show' => ['GET', '/threads/{thread}'],
            'threads.seen' => ['POST', '/threads/{thread}/seen'],
            'threads.share.show' => ['GET', '/threads/{thread}/share'],
            'threads.share.update' => ['PUT', '/threads/{thread}/share', ['mode' => 'only_me']],
            'tasks.show' => ['GET', '/tasks/{task}'],
            'tasks.retry' => ['POST', '/tasks/{task}/retry'],
            'tasks.cancel' => ['POST', '/tasks/{task}/cancel'],
            'tasks.rerun-review' => ['POST', '/tasks/{task}/rerun-review'],
            'tasks.retry-render' => ['POST', '/tasks/{task}/retry-render'],
            'tasks.reroute' => ['POST', '/tasks/{task}/reroute', ['repo' => 'nope']],
            'tasks.co-owner.approve' => ['POST', '/tasks/{task}/co-owner/approve'],
            'tasks.co-owner.request-changes' => ['POST', '/tasks/{task}/co-owner/request-changes', ['comment' => 'x']],
            'tasks.co-owner.reject' => ['POST', '/tasks/{task}/co-owner/reject'],
            'tasks.co-owner.withdraw' => ['POST', '/tasks/{task}/co-owner/withdraw'],
            'tasks.co-owner.check-access' => ['POST', '/tasks/{task}/co-owner/check-access'],
            'tasks.co-owner.tag' => ['POST', '/tasks/{task}/co-owner/tag', ['user_id' => 1]],
            'tasks.co-owner.decline' => ['POST', '/tasks/{task}/co-owner/decline'],
            'tasks.messages.store' => ['POST', '/tasks/{task}/messages', ['message' => 'hello']],
            'tasks.queued-messages.update' => ['PATCH', '/tasks/{task}/queued-messages/5', ['mode' => 'queue']],
            'tasks.queued-messages.destroy' => ['DELETE', '/tasks/{task}/queued-messages/5'],
            'tasks.clarification-answers.store' => ['POST', '/tasks/{task}/clarification-answers', ['answers' => []]],
            'tasks.re-request-review' => ['POST', '/tasks/{task}/re-request-review'],
            'tasks.pr-images.show' => ['GET', '/tasks/{task}/pr-images/abc123', [], 404],
            'artifacts.show' => ['GET', '/artifacts/{task}/shot.png'],
            'artifacts.viewer' => ['GET', '/artifacts/{task}/viewer/shot.png'],
            'task-attachments.show' => ['GET', '/task-attachments/{attachment}'],
            'deployments.show' => ['GET', '/deployments/{deployment}'],
            'deployments.hibernation.update' => ['PATCH', '/deployments/{deployment}/hibernation', ['long_lived' => true]],
            'deployments.rebuild' => ['POST', '/deployments/{deployment}/rebuild'],
            'deployments.destroy' => ['DELETE', '/deployments/{deployment}'],
            'deployments.share.store' => ['POST', '/deployments/{deployment}/share', ['expires_in_days' => 3]],
            'deployments.share.destroy' => ['DELETE', '/deployments/{deployment}/share'],
            'pr-reviews.for-pr' => ['GET', '/pr-reviews/for/' . VisibilityWorld::REPO . '/{pr}'],
            'pr-reviews.for-pr.rerun' => ['POST', '/pr-reviews/for/' . VisibilityWorld::REPO . '/{pr}/rerun'],
        ];

        $result = [];
        foreach ($probes as $name => $probe) {
            $uri = fn (int $id): string => strtr($probe[1], [
                '{thread}' => (string) ($id === $missing ? $missing : $thread),
                '{task}' => (string) ($id === $missing ? $missing : $task),
                '{deployment}' => (string) ($id === $missing ? $missing : $deployment),
                '{attachment}' => (string) ($id === $missing ? $missing : $world->attachment->id),
                '{pr}' => (string) ($id === $missing ? $missing : $world->prReview->pr_number),
            ]);

            $result[$name] = [
                'method' => $probe[0],
                'uri' => $uri(0),
                'payload' => $probe[2] ?? [],
                'missingUri' => $uri($missing),
                ...(isset($probe[3]) ? ['ownerStatus' => $probe[3]] : []),
            ];
        }

        // The auth bounce names the preview by host, not by id.
        $result['deployments.auth-bounce'] = [
            'method' => 'GET',
            'uri' => '/deployments/auth-bounce?to=' . urlencode('https://' . VisibilityWorld::HOSTNAME . '/'),
            'payload' => [],
            'missingUri' => '/deployments/auth-bounce?to=' . urlencode('https://missing-preview.test/'),
            'ownerStatus' => 302,
        ];

        return $result;
    }

    /**
     * Routes proven to carry no thread-derived data.
     *
     * @return list<string>
     */
    public static function global(): array
    {
        return [
            // Sign-in and OAuth hand-offs: no thread data.
            'auth.google', 'auth.google.callback', 'auth.linear.redirect', 'auth.linear.callback',
            // Deploy-wide configuration screens: repositories, skills, MCP, prompts, channels, health, settings.
            'channels', 'health', 'health.refresh', 'health.check.refresh',
            'repos', 'repos.create', 'repos.store', 'repos.github-search', 'repos.github-detect', 'repos.edit',
            'repos.update', 'repos.destroy', 'repos.toggle-active', 'repos.rerun-setup', 'repos.config.refresh',
            'repos.config.migrate', 'repos.review-open-prs', 'repos.risk-profile', 'repos.risk-profile.show',
            'repos.rebuild-deployments', 'repos.manifest.update',
            'repos.claude-account.update', 'repos.unattended-claude-account.update',
            'skills', 'skills.install', 'skills.update', 'skills.upgrade', 'skills.destroy',
            'marketplaces.store', 'marketplaces.refresh', 'marketplaces.destroy',
            'mcp', 'mcp.store', 'mcp.destroy', 'mcp.login.start', 'mcp.login.cancel', 'mcp.login.redirect', 'mcp.logout',
            'prompts', 'prompts.show', 'prompts.update', 'prompts.reset', 'prompts.preview', 'prompts.versions.show',
            'settings.claude-accounts', 'settings.claude-accounts.store', 'settings.claude-accounts.update',
            'settings.claude-accounts.destroy', 'settings.claude-accounts.rotate',
            'settings.github.connect', 'settings.github.callback', 'settings.github.disconnect',
            'settings.linear', 'settings.linear.update', 'settings.linear.disconnect',
            'settings.video', 'settings.video.update', 'settings.video.logo.destroy', 'settings.video.reset', 'settings.video.sample',
            'profile.edit', 'profile.update', 'profile.direct-messages.update', 'profile.email-addresses.store',
            'profile.email-addresses.destroy', 'profile.email-addresses.resend', 'verification.resend', 'account.destroy',
            'email-addresses.confirm.show', 'email-addresses.confirm',
            'video-theme.logo', 'video-theme.sample',
            // Creates a task; reads nothing of another person's thread.
            'tasks.store', 'tasks.setup-card.dismiss',
            // Token-only public image roles for PR bodies; the route lists nothing and resolves by a 26 character token.
            'artifacts.public',
            // Host-only protocol for the broker and the CLI behind the session-store token: no signed-in user exists to filter for.
            'session-store.append', 'session-store.checkpoint', 'session-store.file', 'session-store.files',
            'session-store.length', 'session-store.read', 'session-store.tree',
        ];
    }

    /**
     * Routes with no id that list or aggregate across threads. Each is swept
     * for leaks in VisibilityListSurfacesTest.
     *
     * @return list<string>
     */
    public static function lists(): array
    {
        return ['tasks', 'threads.list', 'deployments', 'costs', 'analytics', 'system', 'pr-reviews', 'observations'];
    }

    /**
     * Preview host endpoints that resolve a deployment by hostname, swept in
     * VisibilityPreviewHostsTest.
     *
     * @return list<string>
     */
    public static function hosts(): array
    {
        return ['deployments.preview-auth', 'deployments.status', 'deployments.wake'];
    }

    /**
     * Application routes (vendor and closure routes excluded), keyed by the
     * name the groups above use.
     *
     * @return array<string, LaravelRoute>
     */
    public static function applicationRoutes(): array
    {
        $routes = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $uses = $route->getAction('uses');

            if (! is_string($uses) || ! str_starts_with($uses, 'App\\')) {
                continue;
            }

            $routes[$route->getName() ?? $route->methods()[0] . ' ' . $route->uri()] = $route;
        }

        return $routes;
    }

    public static function placeholderWorld(): VisibilityWorld
    {
        $world = new VisibilityWorld;
        foreach (['thread', 'child'] as $property) {
            $world->{$property} = (new Thread)->forceFill(['id' => 1]);
        }
        foreach (['task' => YakTask::class, 'childTask' => YakTask::class, 'run' => TaskRun::class,
            'deployment' => BranchDeployment::class, 'attachment' => TaskAttachment::class,
            'artifact' => Artifact::class, 'prReview' => PrReview::class] as $property => $class) {
            $world->{$property} = (new $class)->forceFill(['id' => 1]);
        }
        $world->prReview->pr_number = 77;

        return $world;
    }
}
