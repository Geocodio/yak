<?php

namespace App\Http\Middleware;

use App\Enums\TaskStatus;
use App\Models\YakTask;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user === null ? null : [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'initials' => $user->initials(),
                ],
            ],
            'flash' => function () use ($request) {
                $success = $request->session()->get('success');
                $error = $request->session()->get('error');

                return [
                    'success' => $success,
                    'error' => $error,
                    'id' => ($success !== null || $error !== null) ? (string) Str::uuid() : null,
                ];
            },
            'nav' => fn () => [
                'activeTaskCount' => $user === null ? 0 : YakTask::query()->whereIn('status', TaskStatus::activeValues())->count(),
            ],
            'docs' => ['baseUrl' => (string) config('docs.base_url')],
        ];
    }
}
