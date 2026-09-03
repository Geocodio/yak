<?php

namespace App\Http\Requests\Settings;

use App\Services\SvgLogoValidator;
use App\Services\VideoThemeResolver;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

class SaveVideoThemeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $hex = 'regex:/^(#[0-9a-fA-F]{6}|rgba?\(\s*\d+\s*,\s*\d+\s*,\s*\d+\s*(,\s*[\d.]+\s*)?\))$/';

        return [
            'colors.background' => ['required', 'string', $hex],
            'colors.surface' => ['required', 'string', $hex],
            'colors.ink' => ['required', 'string', $hex],
            'colors.muted' => ['required', 'string', $hex],
            'colors.accent' => ['required', 'string', $hex],
            'colors.done' => ['required', 'string', $hex],
            'colors.captionBg' => ['required', 'string', $hex],
            'fonts.display' => ['required', 'string', 'max:64', Rule::in(VideoThemeResolver::FONT_FAMILIES)],
            'fonts.body' => ['required', 'string', 'max:64', Rule::in(VideoThemeResolver::FONT_FAMILIES)],
            'fonts.mono' => ['required', 'string', 'max:64', Rule::in(VideoThemeResolver::FONT_FAMILIES)],
            'logo' => [
                'nullable',
                'file',
                'mimetypes:image/png,image/svg+xml',
                'max:512',
                $this->safeSvgRule(),
            ],
        ];
    }

    /**
     * The theme logo is served unauthenticated from this app's own origin,
     * so an uploaded SVG must not be able to carry a script payload.
     *
     * The check is keyed off the detected media type and the bytes
     * themselves, never the client-supplied filename: uploading SVG markup
     * named `logo.png` must not skip validation.
     */
    protected function safeSvgRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! $value instanceof UploadedFile) {
                return;
            }

            $contents = (string) $value->get();

            if (! $this->isSvgUpload($value, $contents)) {
                return;
            }

            if (! app(SvgLogoValidator::class)->isSafe($contents)) {
                $fail(__('The logo SVG contains disallowed content. Remove any script, event handler, external reference or foreignObject and try again.'));
            }
        };
    }

    /**
     * Is this upload SVG, as a browser would decide?
     *
     * Neither the client-supplied filename nor its Content-Type are
     * trusted; the decision is made from the detected media type and the
     * bytes themselves.
     */
    public function isSvgUpload(UploadedFile $file, string $contents): bool
    {
        return strtolower((string) $file->getMimeType()) === 'image/svg+xml'
            || app(SvgLogoValidator::class)->looksLikeSvg($contents);
    }
}
