<?php

namespace App\Http\Requests\Api\App;

use App\Services\PreviewAccess;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class PreviewTicketRequest extends FormRequest
{
    private const PATH_MESSAGE = 'The path must start with a single slash and have no spaces.';

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
                'nullable',
                'string',
                'max:2048',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_string($value) && $value !== '' && ! app(PreviewAccess::class)->isSameHostPath($value)) {
                        $fail(self::PATH_MESSAGE);
                    }
                },
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['path.string' => self::PATH_MESSAGE];
    }

    public function previewPath(): string
    {
        $path = $this->validated('path');

        return is_string($path) && $path !== '' ? $path : '/';
    }
}
