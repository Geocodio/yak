<?php

namespace App\Http\Controllers\Tasks;

use App\DataTransferObjects\TaskActionOutcome;
use App\Exceptions\ThreadActionRefused;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tasks\RerouteTaskRequest;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Tasks\TaskActions;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class TaskActionController extends Controller
{
    public function retry(YakTask $task, TaskActions $actions): RedirectResponse
    {
        return $this->respond($task, fn (): TaskActionOutcome => $actions->retry($task));
    }

    public function cancel(Request $request, YakTask $task, TaskActions $actions): RedirectResponse
    {
        /** @var User $canceller */
        $canceller = $request->user();

        return $this->respond($task, fn (): TaskActionOutcome => $actions->cancel($task, $canceller));
    }

    public function rerunReview(YakTask $task, TaskActions $actions): RedirectResponse
    {
        return $this->respond($task, fn (): TaskActionOutcome => $actions->rerunReview($task));
    }

    public function retryRender(YakTask $task, TaskActions $actions): RedirectResponse
    {
        return $this->respond($task, fn (): TaskActionOutcome => $actions->retryRender($task));
    }

    public function reroute(RerouteTaskRequest $request, YakTask $task, TaskActions $actions): RedirectResponse
    {
        return $this->respond($task, fn (): TaskActionOutcome => $actions->reroute($task, $request->validated('repo')));
    }

    /**
     * @param  Closure(): TaskActionOutcome  $action
     */
    private function respond(YakTask $task, Closure $action): RedirectResponse
    {
        try {
            $outcome = $action();
        } catch (ThreadActionRefused $refused) {
            return redirect()->route('tasks.show', $task)->with('error', $refused->getMessage());
        } catch (HttpException $unavailable) {
            if ($unavailable->getStatusCode() !== 503) {
                throw $unavailable;
            }

            return redirect()->route('tasks.show', $task)->with('error', $unavailable->getMessage());
        } catch (ValidationException $invalid) {
            return redirect()->route('tasks.show', $task)->with('error', (string) collect($invalid->errors())->flatten()->first());
        }

        $redirect = redirect()->route('tasks.show', $task);

        return $outcome->sentence === '' ? $redirect : $redirect->with('success', $outcome->sentence);
    }
}
