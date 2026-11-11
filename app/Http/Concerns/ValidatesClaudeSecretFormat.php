<?php

namespace App\Http\Concerns;

use App\Enums\ClaudeAccountKind;
use Closure;

trait ValidatesClaudeSecretFormat
{
    /**
     * @return Closure(string, mixed, Closure(string): void): void
     */
    protected function secretFormatRule(ClaudeAccountKind $kind): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($kind): void {
            $value = (string) $value;

            if ($kind === ClaudeAccountKind::SetupToken && ! str_starts_with($value, 'sk-ant-oat01-')) {
                $fail('This does not look like a setup token. It starts with sk-ant-oat01-. Paste the whole line that claude setup-token printed.');
            }

            if ($kind === ClaudeAccountKind::ApiKey && ! str_starts_with($value, 'sk-ant-')) {
                $fail('This does not look like an API key. It starts with sk-ant-api03-. Copy the whole key from the Anthropic Console.');
            }
        };
    }
}
