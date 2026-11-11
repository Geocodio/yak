<?php

namespace Tests\Support;

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;

/**
 * Every route is classified here, so a new route cannot ship without someone
 * deciding what it reveals about a private thread. The coverage test fails
 * on any route that is in none of the four groups.
 */
class VisibilityRoutes
{
    /** @var array<string, array{0: string, 1: string, 2?: array<string, mixed>, 3?: int}> method, uri template, payload, owner status */
    private const PROBES = [
        'threads.show' => ['GET', '/threads/{thread}'],
        'threads.seen' => ['POST', '/threads/{thread}/seen'],
        'threads.share.show' => ['GET', '/threads/{thread}/share'],
        'threads.share.update' => ['PUT', '/threads/{thread}/share', ['mode' => 'only_me']],
        'threads.diff.show' => ['GET', '/threads/{thread}/diff'],
        'threads.diff.files.show' => ['GET', '/threads/{thread}/diff/files/{file}'],
        'threads.environment.show' => ['GET', '/threads/{thread}/environment'],
        'threads.files.tree' => ['GET', '/threads/{thread}/files?scope=repo'],
        'threads.files.show' => ['GET', '/threads/{thread}/files/content?scope=repo&path=README.md'],
        'threads.files.update' => ['PUT', '/threads/{thread}/files/content', ['scope' => 'repo', 'path' => 'a.txt', 'content' => 'x', 'base_sha256' => ''], 409],
        'threads.events.index' => ['GET', '/threads/{thread}/events'],
        'threads.events.show' => ['GET', '/threads/{thread}/events/{sequence}'],
        'threads.pull-request.store' => ['POST', '/threads/{thread}/pull-request', ['acknowledged_private_publish' => true], 302],
        'threads.teleport-request.store' => ['POST', '/threads/{thread}/teleport-request', ['acknowledged_private_publish' => true]],
        'threads.teleport-request.destroy' => ['DELETE', '/threads/{thread}/teleport-request'],
        'threads.messages.store' => ['POST', '/threads/{thread}/messages', ['message' => ''], 302],
        'threads.stop' => ['POST', '/threads/{thread}/stop'],
        'threads.continue' => ['POST', '/threads/{thread}/continue'],
        'threads.restore.retry' => ['POST', '/threads/{thread}/restore/retry'],
        'threads.repositories.progress' => ['GET', '/threads/{thread}/repositories'],
        'threads.repositories.update' => ['PATCH', '/threads/{thread}/repositories/{repository}', ['role' => 'context'], 302],
        'threads.repositories.retry' => ['POST', '/threads/{thread}/repositories/{repository}/retry', [], 302],
        'threads.stack.show' => ['GET', '/threads/{thread}/stack'],
        'threads.stack.limit' => ['PATCH', '/threads/{thread}/stack/limit', ['limit' => 2]],
        'threads.stack.stop' => ['POST', '/threads/{thread}/stack/stop'],
        'threads.stack.children.store' => ['POST', '/threads/{thread}/stack/children', ['name' => 'scout', 'brief' => 'Look around.', 'kind' => 'research']],
        'threads.stack.children.diff' => ['GET', '/threads/{thread}/stack/children/{child}/diff'],
        'threads.stack.take-over' => ['POST', '/threads/{child}/stack/take-over'],
        'threads.stack.split' => ['POST', '/threads/{child}/stack/split'],
        'threads.stack.drop' => ['POST', '/threads/{child}/stack/drop'],
        'threads.pull-requests.show' => ['GET', '/threads/{thread}/pull-requests'],
        'threads.pull-requests.order' => ['PUT', '/threads/{thread}/pull-requests/order', ['order' => [999999]], 302],
        'threads.pull-requests.fix' => ['POST', '/threads/{thread}/pull-requests/{row}/fix', [], 202],
        'threads.preview.status' => ['GET', '/threads/{thread}/preview/status'],
        'threads.preview.heartbeat' => ['POST', '/threads/{thread}/preview/heartbeat'],
        'threads.preview.restore' => ['POST', '/threads/{thread}/preview/restore', [], 202],
        'threads.terminal-sessions.store' => ['POST', '/threads/{thread}/terminal-sessions', [], 403],
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
        'tasks.re-request-review' => ['POST', '/tasks/{task}/re-request-review', [], 502],
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

        $probes = self::PROBES;

        $result = [];
        foreach ($probes as $name => $probe) {
            $uri = fn (int $id): string => strtr($probe[1], [
                '{thread}' => (string) ($id === $missing ? $missing : $thread),
                '{child}' => (string) ($id === $missing ? $missing : $world->child->id),
                '{task}' => (string) ($id === $missing ? $missing : $task),
                '{deployment}' => (string) ($id === $missing ? $missing : $deployment),
                '{attachment}' => (string) ($id === $missing ? $missing : $world->attachment->id),
                '{repository}' => (string) ($id === $missing ? $missing : $world->repository->id),
                '{file}' => (string) ($id === $missing ? $missing : $world->diffFile->id),
                '{sequence}' => (string) ($id === $missing ? $missing : $world->event->sequence),
                '{row}' => (string) ($id === $missing ? $missing : $world->pullRequestRow->id),
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

        // The auth bounce names the preview by host, not by id, and is signed.
        $bounce = fn (string $host): string => URL::temporarySignedRoute('deployments.auth-bounce', now()->addHour(), ['to' => "https://{$host}/"]);
        $result['deployments.auth-bounce'] = [
            'method' => 'GET',
            'uri' => $bounce(VisibilityWorld::HOSTNAME),
            'payload' => [],
            'missingUri' => $bounce('missing-preview.test'),
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
            // Device pairing and the viewer's own paired devices: per user, no thread data.
            'device.show', 'device.approve', 'device.deny', 'settings.devices', 'settings.devices.destroy',
            // New-thread form and creation: reads the viewer's own repositories and accounts only.
            'threads.new', 'threads.store', 'threads.repository-access',
            // Device-token protocol for the CLI: no web session; each controller resolves the thread through the device owner's visibility.
            'cli.device.code', 'cli.device.token', 'cli.me', 'cli.device.destroy', 'cli.repos.resolve',
            'GET api/cli/v1/threads/by-session/{sessionId}', 'GET api/cli/v1/threads/{thread}', 'POST api/cli/v1/teleports',
            'GET api/cli/v1/teleports/{teleport}', 'POST api/cli/v1/teleports/{teleport}/complete',
            'POST api/cli/v1/teleports/{teleport}/cancel', 'GET api/cli/v1/threads/{thread}/stack-messages',
            'POST api/cli/v1/threads/{thread}/stack-messages/ack', 'GET api/cli/v1/threads/{thread}/ssh',
            'GET api/cli/v1/threads/{thread}/session/length', 'POST api/cli/v1/threads/{thread}/session/append',
            'POST api/cli/v1/threads/{thread}/session/checkpoint', 'PUT api/cli/v1/threads/{thread}/session/file',
            'GET api/cli/v1/threads/{thread}/session/files', 'GET api/cli/v1/threads/{thread}/session/read',
            'GET api/cli/v1/threads/{thread}/session/tree',
            // Per-user settings: the viewer's own SSH keys and push devices; no thread data.
            'settings.ssh-keys', 'settings.ssh-keys.store', 'settings.ssh-keys.refresh', 'settings.ssh-keys.destroy',
            'settings.notifications', 'settings.notifications.preferences', 'settings.notifications.devices.store',
            'settings.notifications.devices.update', 'settings.notifications.devices.destroy', 'settings.notifications.devices.test',
            // Broker-only SSH protocol behind the internal token: no signed-in user exists to filter for.
            'internal.ssh.authorized-keys', 'internal.ssh.listeners', 'internal.ssh.wake', 'internal.ssh.terminal-redeem', 'internal.ssh.activity',
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
     * Preview host endpoints that resolve a deployment or thread by hostname,
     * swept in VisibilityPreviewHostsTest and ThreadPreviewVisibilityTest.
     *
     * @return list<string>
     */
    public static function hosts(): array
    {
        return ['deployments.preview-auth', 'deployments.status', 'deployments.wake', 'threads.preview.wake', 'threads.preview.host-status'];
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

    /**
     * The probe names, known without a database (dataset providers run before the app boots).
     *
     * @return list<string>
     */
    public static function probeNames(): array
    {
        return [...array_keys(self::PROBES), 'deployments.auth-bounce'];
    }
}
