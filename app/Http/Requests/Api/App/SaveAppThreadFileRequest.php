<?php

namespace App\Http\Requests\Api\App;

use App\Http\Requests\Threads\SaveThreadFileRequest;
use Closure;

class SaveAppThreadFileRequest extends SaveThreadFileRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['content'][] = function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && strlen($value) > (int) config('yak.files.max_edit_bytes')) {
                $fail('The file is too large to save from the app.');
            }
        };

        return $rules;
    }
}
