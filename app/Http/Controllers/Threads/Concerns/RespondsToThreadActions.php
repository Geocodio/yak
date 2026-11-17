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
    private function respond(Thread $thread, Closure $action): RedirectResponse
    {
        try {
            $result = $action();
        } catch (ThreadActionRefused $refused) {
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
