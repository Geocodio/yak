<?php

namespace App\Http\Requests\Settings;

use App\Models\ClaudeAccount;
use App\Services\Settings\EditableSettings;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the Limits page. Every rule comes from the setting definitions; the request may carry any subset of
 * them, and the ordering checks fall back to the value in effect for a setting that is not sent.
 */
class UpdateLimitsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', [ClaudeAccount::class, true]) ?? false;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        $rules = ['values' => ['required', 'array']];

        foreach (app(EditableSettings::class)->definitions() as $definition) {
            $rules["values.{$definition['id']}"] = match ($definition['type']) {
                'bool' => ['sometimes', 'boolean'],
                'decimal' => ['sometimes', 'numeric', "min:{$definition['min']}", "max:{$definition['max']}"],
                'int' => ['sometimes', 'integer', "min:{$definition['min']}", "max:{$definition['max']}"],
            };
        }

        return $rules;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        $messages = [];

        foreach (app(EditableSettings::class)->definitions() as $definition) {
            $label = $definition['label'];
            $field = "values.{$definition['id']}";
            $messages["{$field}.integer"] = "{$label} must be a whole number, like 4.";
            $messages["{$field}.numeric"] = "{$label} must be a number, like 5 or 7.50.";
            $messages["{$field}.boolean"] = "{$label} must be on or off.";
            $messages["{$field}.min"] = "{$label} must be at least {$definition['min']}. Enter {$definition['min']} or more.";
            $messages["{$field}.max"] = "{$label} must be at most {$definition['max']}. Enter {$definition['max']} or less.";
        }

        return $messages;
    }

    /**
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $settings = app(EditableSettings::class);
                $values = [];

                foreach ($settings->definitions() as $definition) {
                    $values[$definition['id']] = $this->input("values.{$definition['id']}", $settings->currentValue($definition));
                }

                if ((int) $values['person_awake_cap'] > (int) $values['awake_cap']) {
                    $validator->errors()->add('values.person_awake_cap', "Awake sandboxes per person cannot be higher than awake sandboxes on this server ({$values['awake_cap']}). Lower it, or raise the server limit first.");
                }

                if ((int) $values['child_awake_limit'] > (int) $values['person_awake_cap']) {
                    $validator->errors()->add('values.child_awake_limit', "Awake sub-threads per thread cannot be higher than awake sandboxes per person ({$values['person_awake_cap']}). Lower it, or raise the per person limit first.");
                }
            },
        ];
    }
}
