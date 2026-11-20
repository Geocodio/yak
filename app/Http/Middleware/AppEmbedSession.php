<?php

namespace App\Http\Middleware;

use App\Enums\DeviceKind;
use App\Models\Device;
use App\Models\User;
use App\Support\AppEmbedAudit;
use App\Support\AppEmbedRoutes;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps the phone's embedded web session restricted.
 *
 * The session lives on the `app-embed` guard and the `web` guard is never logged in, so a route this middleware
 * forgets reaches a plain `auth` check as a guest. Anything inconsistent about the session ends it.
 */
class AppEmbedSession
{
    private const GUARD = 'app-embed';

    public function handle(Request $request, Closure $next): Response
    {
        $session = $request->session();
        $guard = Auth::guard(self::GUARD);

        // The hand-off replaces whatever session the jar held, so it neither needs nor is blocked by the old one.
        if ($request->routeIs('app.embed.session') || ! ($guard->check() || $session->has($guard->getName()) || $session->has('app_embed'))) {
            return $next($request);
        }

        $reason = $this->reasonToEnd($session->get('app_embed'), $guard->user());

        if ($reason !== null) {
            $guard->forgetUser();
            $session->invalidate();
            AppEmbedAudit::log('app_embed.ended', $request, ['reason' => $reason]);

            return $this->secure($request->expectsJson() || $request->header('X-Inertia') !== null
                ? new JsonResponse(['message' => 'Unauthenticated.'], 401)
                : response('Unauthenticated.', 401), $request);
        }

        abort_unless($this->isAllowed($request), 404);

        // The default guard is restored before the session is saved, so a database session row of an embed session
        // has a null `user_id`. The user is found through the `app-embed` login key inside the session payload.
        $previousGuard = Auth::getDefaultDriver();
        Auth::shouldUse(self::GUARD);

        try {
            $response = $next($request);
        } finally {
            Auth::shouldUse($previousGuard);
        }

        return $this->secure($response, $request);
    }

    /**
     * Why this session must end, or null while the phone, the user and the clock still back it.
     */
    private function reasonToEnd(mixed $embed, ?User $user): ?string
    {
        if (! is_array($embed) || ! is_int($embed['device_id'] ?? null) || ! is_int($embed['expires_at'] ?? null)) {
            return 'invalid';
        }

        if ($embed['expires_at'] <= now()->getTimestamp()) {
            return 'expired';
        }

        $device = Device::query()->find($embed['device_id']);

        if (! $device instanceof Device || $device->kind !== DeviceKind::Phone || $device->isRevoked()) {
            return 'revoked';
        }

        if (! $user instanceof User) {
            return 'user_gone';
        }

        return $device->user_id === $user->id ? null : 'user';
    }

    private function isAllowed(Request $request): bool
    {
        $route = $request->route();

        return $route !== null && (in_array($route->getName(), AppEmbedRoutes::ALLOWED, true) || $route->uri() === AppEmbedRoutes::BROADCAST_AUTH_URI);
    }

    /**
     * Never cached, never a referrer to other sites, and a redirect to the full thread page stays on the embed page.
     */
    private function secure(Response $response, Request $request): Response
    {
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Referrer-Policy', 'same-origin');

        foreach (['Location', 'X-Inertia-Location'] as $header) {
            $location = $response->headers->get($header);

            if ($location !== null && preg_match('#^(?:' . preg_quote($request->getSchemeAndHttpHost(), '#') . ')?/threads/([A-Za-z0-9]{8})$#D', $location, $matches) === 1) {
                $response->headers->set($header, $request->getSchemeAndHttpHost() . '/app/embed/threads/' . $matches[1]);
            }
        }

        return $response;
    }
}
