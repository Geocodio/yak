<?php

namespace App\Http\Requests\Settings;

use App\Models\User;
use App\Models\UserEmailAddress;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class StoreEmailAddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255', function (string $attribute, mixed $value, Closure $fail): void {
                $email = UserEmailAddress::normalize((string) $value);

                $isSignInAddress = User::query()->whereRaw('lower(email) = ?', [$email])->exists();
                // One row per address across all users, confirmed or not.
                $isTaken = UserEmailAddress::query()->where('email', $email)->exists();

                if ($isSignInAddress || $isTaken) {
                    $fail('This address is already in use.');
                }
            }],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['email' => UserEmailAddress::normalize($this->string('email')->toString())]);
    }
}
