<?php

namespace App\Http\Requests\Api\App;

use App\Support\AppEmbedRoutes;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class WebSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'path' => [
                'required',
                'string',
                'max:300',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_string($value) || ! AppEmbedRoutes::isEmbedPath($value)) {
                        $fail('The path must be an embedded page of Yak.');
                    }
                },
            ],
        ];
    }

    public function embedPath(): string
    {
        return (string) $this->validated('path');
    }
}
