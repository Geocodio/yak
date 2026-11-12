<?php

namespace App\Http\Controllers\Devices;

use App\Http\Controllers\Controller;
use App\Http\Requests\Devices\ApproveDeviceRequest;
use App\Http\Resources\DeviceApprovalData;
use App\Models\DeviceAuthorization;
use App\Models\User;
use App\Services\Devices\DeviceFlow;
use App\Services\Devices\DeviceFlowException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Inertia;
use Inertia\Response;

class DeviceApprovalController extends Controller
{
    private const LOOKUPS_PER_MINUTE = 10;

    public function __construct(private DeviceFlow $deviceFlow) {}

    public function show(Request $request): Response
    {
        $user = $this->user($request);
        $code = $request->query('code');
        $email = $user->email;

        if (! is_string($code) || trim($code) === '') {
            return $this->render(DeviceApprovalData::make('enter', null, null, $email));
        }

        $this->throttle($user);
        $authorization = $this->deviceFlow->findInAnyState($code);

        if ($authorization === null) {
            return $this->render(DeviceApprovalData::make('enter-error', null, $code, $email));
        }

        $result = $request->query('result');

        if ($result === 'approved' && in_array($authorization->status, ['approved', 'redeemed'], true) && $authorization->user_id === $user->id) {
            return $this->render(DeviceApprovalData::make('approved', $authorization, null, $email, $authorization->device?->name));
        }

        if ($result === 'denied' && $authorization->status === 'denied') {
            return $this->render(DeviceApprovalData::make('denied', $authorization, null, $email));
        }

        if ($authorization->isExpired()) {
            return $this->render(DeviceApprovalData::make('expired', $authorization, null, $email));
        }

        if ($authorization->status !== 'pending') {
            return $this->render(DeviceApprovalData::make('enter-error', null, $code, $email));
        }

        return $this->render(DeviceApprovalData::make('confirm', $authorization, null, $email));
    }

    public function approve(ApproveDeviceRequest $request): RedirectResponse
    {
        $user = $this->user($request);
        $authorization = $this->pending($request, $user);

        if ($authorization === null) {
            return $this->backToPage((string) $request->input('code'));
        }

        try {
            $this->deviceFlow->approve($authorization, $user, $request->input('name'));
        } catch (DeviceFlowException) {
            return $this->backToPage($authorization->user_code);
        }

        return $this->backToPage($authorization->user_code, 'approved');
    }

    public function deny(ApproveDeviceRequest $request): RedirectResponse
    {
        $authorization = $this->pending($request, $this->user($request));

        if ($authorization === null) {
            return $this->backToPage((string) $request->input('code'));
        }

        $this->deviceFlow->deny($authorization);

        return $this->backToPage($authorization->user_code, 'denied');
    }

    private function pending(ApproveDeviceRequest $request, User $user): ?DeviceAuthorization
    {
        $this->throttle($user);

        return $this->deviceFlow->find((string) $request->input('code'));
    }

    private function throttle(User $user): void
    {
        $key = 'device-code-lookup:' . $user->id;

        abort_if(RateLimiter::tooManyAttempts($key, self::LOOKUPS_PER_MINUTE), 429);

        RateLimiter::hit($key, 60);
    }

    private function backToPage(string $code, ?string $result = null): RedirectResponse
    {
        return redirect()->route('device.show', array_filter(['code' => $code, 'result' => $result]));
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $props
     */
    private function render(array $props): Response
    {
        return Inertia::render('Device/Approve', $props);
    }
}
