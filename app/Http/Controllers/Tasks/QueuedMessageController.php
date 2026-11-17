<?php

namespace App\Http\Controllers\Tasks;

use App\Enums\SteeringMode;
use App\Exceptions\ThreadActionRefused;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tasks\UpdateQueuedMessageRequest;
use App\Models\YakTask;
use App\Services\Threads\QueuedMessages;
use App\Support\TaskPageUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

/**
 * Messages waiting for a busy task: switch one between queued and steered,
 * or withdraw it (to drop it, or to edit it back in the composer). Each
 * may already have reached Yak by the time the request lands.
 */
class QueuedMessageController extends Controller
{
    public function update(UpdateQueuedMessageRequest $request, YakTask $task, int $message, QueuedMessages $queued): RedirectResponse
    {
        try {
            $queued->update($task->rootTask(), $request->user() ?? abort(401), $message, $request->enum('mode', SteeringMode::class), null);
        } catch (ThreadActionRefused $refusal) {
            return $this->back($task, $refusal->getMessage());
        } catch (ValidationException $invalid) {
            return $this->back($task, $invalid->validator->errors()->first('mode'));
        }

        return $this->back($task, null);
    }

    public function destroy(YakTask $task, int $message, QueuedMessages $queued): RedirectResponse
    {
        try {
            $queued->withdraw($task->rootTask(), $message);
        } catch (ThreadActionRefused $refusal) {
            return $this->back($task, $refusal->getMessage());
        }

        return $this->back($task, null);
    }

    private function back(YakTask $task, ?string $error): RedirectResponse
    {
        $redirect = redirect()->to(TaskPageUrl::forOrLog($task));

        return $error === null ? $redirect : $redirect->with('error', $error);
    }
}
