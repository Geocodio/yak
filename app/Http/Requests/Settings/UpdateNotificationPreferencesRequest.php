<?php

namespace App\Http\Requests\Settings;

use App\Services\Push\PushEvent;
use Illuminate\Foundation\Http\FormRequest;

class UpdateNotificationPreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $rules = [];

        foreach (PushEvent::cases() as $event) {
            $rules[$event->value] = ['required', 'boolean'];
        }

        return $rules;
    }
}
