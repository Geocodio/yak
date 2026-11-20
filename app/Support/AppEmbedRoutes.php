<?php

namespace App\Support;

use Illuminate\Http\Request;
use Throwable;

/**
 * What a phone's embedded web view may reach. Everything else answers 404 to an embed session.
 */
final class AppEmbedRoutes
{
    /** Where the embed session lives at most, whatever the idle lifetime says. */
    public const SESSION_HOURS = 12;

    /** The only target paths a hand-off code may be bound to. */
    private const PATH_PATTERNS = [
        '#^/app/embed/threads/[A-Za-z0-9]{8}$#D',
        '#^/app/embed/deployments/\d{1,20}$#D',
        '#^/app/embed/pr-reviews/[A-Za-z0-9._-]{1,100}/[A-Za-z0-9._-]{1,100}/\d{1,10}$#D',
    ];

    /**
     * Route names an embed session may call: the thread page, its reads and the card actions. The phone token
     * already does all of this through the app API, and the native composer stays the only sender of messages.
     *
     * @var list<string>
     */
    public const ALLOWED = [
        'app.embed.threads.show',

        'threads.events.index',
        'threads.events.show',
        'threads.local-turns',
        'threads.runs.log',
        'threads.tasks.log',
        'threads.repositories.progress',
        'threads.co-owner.candidates',
        'threads.stack.show',
        'shared-files.show',
        'shared-files.raw',
        'tasks.pr-images.show',
        'task-attachments.show',
        'artifacts.viewer',

        'threads.clarification-answers.store',
        'threads.repository-answer.store',
        'threads.co-owner.approve',
        'threads.co-owner.request-changes',
        'threads.co-owner.reject',
        'threads.co-owner.withdraw',
        'threads.co-owner.check-access',
        'threads.co-owner.tag',
        'threads.co-owner.decline',
        'threads.retry',
        'threads.cancel',
        'threads.move',
        'threads.setup-and-continue',
        'threads.request-review',
        'threads.retry-render',
        'threads.continue',
        'threads.restore.retry',
        'threads.seen',
        'threads.shared-files.team-share',
        'threads.pull-requests.fix',
    ];

    /** The one allowed route without a name: the framework's channel authorization endpoint. */
    public const BROADCAST_AUTH_URI = 'broadcasting/auth';

    /**
     * Same-host check first (no control characters, no `//`, no backslash), then no `.` or `..` segment, then one of
     * the fixed patterns, so a fragment, a query, percent-encoding and dot segments never match. Last, the router
     * must resolve the path to an allowed `app/embed/` page: a pattern whose route does not exist yet is refused.
     */
    public static function isEmbedPath(string $path): bool
    {
        if (preg_match('/[\x00-\x20\x7f]/', $path) === 1 || preg_match('#^/(?![/\\\\])#', $path) !== 1) {
            return false;
        }

        if (preg_match('#/\.{1,2}(/|$)#', $path) === 1) {
            return false;
        }

        foreach (self::PATH_PATTERNS as $pattern) {
            if (preg_match($pattern, $path) === 1) {
                return self::resolvesToAllowedPage($path);
            }
        }

        return false;
    }

    private static function resolvesToAllowedPage(string $path): bool
    {
        try {
            $route = app('router')->getRoutes()->match(Request::create($path));
        } catch (Throwable) {
            return false;
        }

        return str_starts_with($route->uri(), 'app/embed/') && in_array($route->getName(), self::ALLOWED, true);
    }
}
