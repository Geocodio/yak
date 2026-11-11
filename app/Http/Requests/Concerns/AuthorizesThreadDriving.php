<?php

namespace App\Http\Requests\Concerns;

use App\Models\Thread;
use Illuminate\Support\Facades\Gate;

/**
 * For Form Requests bound to a {thread} route that change the thread. A hidden
 * thread answers exactly like a missing one, and a viewer who cannot drive is
 * refused, both before any input is validated.
 */
trait AuthorizesThreadDriving
{
    public function authorize(): bool
    {
        $thread = $this->route('thread');

        abort_unless($thread instanceof Thread && Gate::allows('view', $thread), 404);

        return Gate::allows('drive', $thread);
    }
}
