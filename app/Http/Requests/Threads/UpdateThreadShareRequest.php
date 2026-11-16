<?php

namespace App\Http\Requests\Threads;

use App\Enums\ThreadShareMode;
use App\Models\Thread;
use App\Services\Threads\ThreadAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UpdateThreadShareRequest extends FormRequest
{
    /**
     * A hidden thread answers like a missing one; a visible thread the user
     * does not own is refused.
     */
    public function authorize(): bool
    {
        $thread = $this->route('thread');

        abort_unless($thread instanceof Thread && Gate::allows('view', $thread), 404);

        return app(ThreadAccess::class)->isOwner($this->user(), $thread);
    }

    /**
     * Unknown or deleted ids are dropped by ThreadSharing, not rejected.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'mode' => ['required', Rule::enum(ThreadShareMode::class)],
            'user_ids' => ['required_if:mode,' . ThreadShareMode::People->value, 'array', 'max:200'],
            'user_ids.*' => ['integer'],
        ];
    }

    public function mode(): ThreadShareMode
    {
        return ThreadShareMode::from($this->string('mode')->toString());
    }

    /**
     * @return list<int>
     */
    public function userIds(): array
    {
        return array_values(array_map('intval', (array) $this->input('user_ids', [])));
    }
}
