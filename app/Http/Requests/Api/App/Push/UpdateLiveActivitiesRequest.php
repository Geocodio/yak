<?php

namespace App\Http\Requests\Api\App\Push;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLiveActivitiesRequest extends FormRequest
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
        return [
            'threads' => ['present', 'array', 'max:10'],
            'threads.*' => ['string', 'size:8', 'regex:/^[0-9a-hjkmnp-tv-z]{8}$/', 'distinct'],
        ];
    }
}
