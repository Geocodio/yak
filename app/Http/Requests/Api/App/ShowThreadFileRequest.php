<?php

namespace App\Http\Requests\Api\App;

class ShowThreadFileRequest extends ListThreadFilesRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [...parent::rules(), 'path' => ['required', 'string', 'max:1024']];
    }
}
