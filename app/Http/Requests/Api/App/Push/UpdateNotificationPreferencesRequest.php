<?php

namespace App\Http\Requests\Api\App\Push;

use App\Services\Push\PushEvent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateNotificationPreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        $rules = ['events' => ['required', 'array']];

        foreach (PushEvent::cases() as $event) {
            $rules["events.{$event->value}"] = ['sometimes', 'boolean'];
        }

        return $rules;
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $events = $this->input('events');

            if (is_array($events) && array_intersect(array_keys($events), $this->knownEvents()) === []) {
                $validator->errors()->add('events', 'Choose at least one notification to change.');
            }
        }];
    }

    /**
     * Only the known switches, as booleans.
     *
     * @return array<string, bool>
     */
    public function preferences(): array
    {
        return array_map('boolval', array_intersect_key($this->validated('events'), array_flip($this->knownEvents())));
    }

    /**
     * @return list<string>
     */
    private function knownEvents(): array
    {
        return array_map(fn (PushEvent $event): string => $event->value, PushEvent::cases());
    }
}
