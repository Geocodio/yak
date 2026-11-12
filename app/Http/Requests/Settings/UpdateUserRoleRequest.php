<?php

namespace App\Http\Requests\Settings;

use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'role' => ['required', Rule::in(['admin', 'member'])],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                /** @var User $target */
                $target = $this->route('user');

                if ($target->is($this->user())) {
                    $validator->errors()->add('role', 'You cannot change your own role.');
                } elseif ($target->isAdminByConfig()) {
                    $validator->errors()->add('role', 'This role is set in the server config.');
                }
            },
        ];
    }
}
