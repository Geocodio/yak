<?php

namespace App\Http\Concerns;

use App\Enums\ClaudeAccountKind;
use Closure;

trait ValidatesClaudeSecretFormat
{
    /**
     * Removes every whitespace character, so a token pasted with line breaks or
     * stray spaces still connects.
     */
    protected function cleanSecret(string $secret): string
    {
        return (string) preg_replace('/\s+/u', '', $secret);
    }

    protected function detectKind(string $secret): ?ClaudeAccountKind
    {
        return match (true) {
            str_starts_with($secret, 'sk-ant-oat01-') => ClaudeAccountKind::SetupToken,
            str_starts_with($secret, 'sk-ant-') => ClaudeAccountKind::ApiKey,
            default => null,
        };
    }

    /**
     * Fails unless the secret is a recognisable Claude credential and, when an
     * expected kind is given, of that kind.
     *
     * @return Closure(string, mixed, Closure(string): void): void
     */
    protected function secretFormatRule(?ClaudeAccountKind $expectedKind = null): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($expectedKind): void {
            $detectedKind = $this->detectKind((string) $value);

            if ($detectedKind === null) {
                $fail('This does not look like a Claude token or API key. A setup token starts with sk-ant-oat01-. Paste the whole line that claude setup-token printed.');

                return;
            }

            if ($expectedKind !== null && $detectedKind !== $expectedKind) {
                $fail($expectedKind === ClaudeAccountKind::SetupToken
                    ? 'This account uses a setup token. Add the API key as a new account.'
                    : 'This account uses an API key. Add the setup token as a new account.');
            }
        };
    }

    protected function prepareSecretForValidation(): void
    {
        $secret = $this->cleanSecret((string) $this->input('secret'));

        $this->merge([
            'secret' => $secret,
            'kind' => $this->detectKind($secret)?->value,
        ]);
    }
}
