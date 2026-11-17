<?php

namespace App\Http\Controllers\Threads\Concerns;

use App\Exceptions\ThreadActionRefused;
use App\Models\Thread;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The answers every thread action route gives: back to the thread with the
 * outcome flashed, a flashed refusal for a closed thread or a refused action,
 * and a validation failure when the thread has no task the action fits.
 */
trait RespondsToThreadActions
{
    use GuardsThreadActions;

    /**
     * @throws ValidationException
     */
    private function nothingTo(string $verb): never
    {
        $sentence = "This thread has nothing to {$verb}.";
        session()->flash('error', $sentence);

        throw ValidationException::withMessages(['thread' => $sentence]);
    }

    /**
     * @param  Closure(): (string|RedirectResponse|null)  $action  returns the sentence to flash, a redirect of its own, or null
     */
    /**
     * Runs the guard of an action that has a body, before the body is validated. A refusal answers like {@see respond()} does.
     *
     * @template T
     *
     * @param  Closure(): T  $guard
     * @return T|RedirectResponse
     */
    private function guard(Thread $thread, Closure $guard): mixed
    {
        $guarded = null;
        $redirect = $this->respond($thread, function () use ($guard, &$guarded): null {
            $guarded = $guard();

            return null;
        });

        return $guarded ?? $redirect;
    }

    private function respond(Thread $thread, Closure $action): RedirectResponse
    {
        try {
            $result = $action();
        } catch (ThreadActionRefused $refused) {
            if (str_starts_with($refused->errorCode, 'nothing_to_')) {
                $this->nothingTo(substr($refused->errorCode, strlen('nothing_to_')));
            }

            return redirect()->route('threads.show', $thread)->with('error', $refused->getMessage());
        } catch (HttpException $unavailable) {
            if ($unavailable->getStatusCode() !== 503) {
                throw $unavailable;
            }

            return redirect()->route('threads.show', $thread)->with('error', $unavailable->getMessage());
        } catch (ValidationException $invalid) {
            return redirect()->route('threads.show', $thread)->with('error', (string) collect($invalid->errors())->flatten()->first());
        }

        if ($result instanceof RedirectResponse) {
            return $result;
        }

        $redirect = redirect()->route('threads.show', $thread);

        return $result === null || $result === '' ? $redirect : $redirect->with('success', $result);
    }
}
