<?php

namespace App\Services\ClaudeAccounts;

use App\DataTransferObjects\AccountValidation;
use App\Enums\ClaudeAccountKind;
use App\Enums\ClaudeAccountStatus;
use App\Models\ClaudeAccount;
use App\Services\Threads\AuthProxyPlaceholders;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Throwable;

/**
 * Proves a pasted credential works by running one Haiku turn on the host in
 * throwaway directories. The proxy resolves a placeholder to an account through
 * the database, so the secret lives on a throwaway account row for the length
 * of the check. The process environment only ever holds the placeholder.
 */
class ClaudeAccountValidator
{
    public function __construct(private readonly AuthProxyPlaceholders $placeholders) {}

    public function validate(ClaudeAccountKind $kind, string $secret): AccountValidation
    {
        $homeDirectory = $this->makeTemporaryDirectory();
        $configDirectory = $this->makeTemporaryDirectory();

        $account = ClaudeAccount::query()->create([
            'name' => 'validation-' . Str::lower(Str::random(12)),
            'kind' => $kind,
            'secret' => $secret,
            'status' => ClaudeAccountStatus::Invalid,
            'owner_user_id' => null,
            'validated_at' => null,
        ]);
        $subject = 'validation-' . $account->id;

        try {
            $placeholder = $this->placeholders->issue($subject, $account, '127.0.0.1');

            $result = Process::timeout(120)
                ->env($this->environment($placeholder, $homeDirectory, $configDirectory))
                ->run(['claude', '-p', '--model', 'haiku', '--max-turns', '1', '--output-format', 'json', 'Reply with OK']);

            $decoded = json_decode($result->output(), true);

            if ($result->successful() && is_array($decoded) && ($decoded['is_error'] ?? true) === false) {
                return new AccountValidation(true);
            }

            $text = is_array($decoded) && isset($decoded['result']) && is_string($decoded['result'])
                ? $decoded['result']
                : trim($result->output() . "\n" . $result->errorOutput());

            return new AccountValidation(false, $this->describeFailure($kind, $text, $secret));
        } catch (Throwable $exception) {
            return new AccountValidation(false, $this->describeFailure($kind, $exception->getMessage(), $secret, thrown: true));
        } finally {
            try {
                $this->placeholders->revoke($subject);
            } catch (Throwable) {
                // The throwaway row is deleted below, which already stops the placeholder resolving.
            }

            $account->delete();
            File::deleteDirectory($homeDirectory);
            File::deleteDirectory($configDirectory);
        }
    }

    /**
     * Symfony inherits the parent environment unless a variable is set to
     * false, so every inherited name is cleared before the allowed ones are set.
     *
     * @return array<string, string|false>
     */
    private function environment(string $placeholder, string $homeDirectory, string $configDirectory): array
    {
        $cleared = array_fill_keys(array_keys(getenv()), false);

        return [
            ...$cleared,
            'PATH' => (string) getenv('PATH'),
            'HOME' => $homeDirectory,
            'CLAUDE_CONFIG_DIR' => $configDirectory,
            'ANTHROPIC_BASE_URL' => (string) config('yak.threads.auth_proxy_url'),
            'CLAUDE_CODE_OAUTH_TOKEN' => $placeholder,
            'CLAUDE_CODE_DISABLE_NONESSENTIAL_TRAFFIC' => '1',
        ];
    }

    private function describeFailure(ClaudeAccountKind $kind, string $output, string $secret, bool $thrown = false): string
    {
        $message = trim(str_replace($secret, '[redacted]', $output));

        if ($thrown || $message === '') {
            return 'Yak could not reach Claude to check it (the request timed out). Nothing was saved. Try again in a minute.';
        }

        if (preg_match('/\b401\b|authentication|unauthorized|invalid (x-api-key|api key|token|bearer)/i', $message) === 1) {
            return $kind === ClaudeAccountKind::SetupToken
                ? 'Claude rejected this token (401). It may have been revoked or may have expired. Run claude setup-token again and paste the new one.'
                : 'Claude rejected this key (401). It may have been revoked, or it belongs to a workspace without access to Haiku. Check it in the Anthropic Console.';
        }

        return mb_substr($message, 0, 300);
    }

    private function makeTemporaryDirectory(): string
    {
        $path = sys_get_temp_dir() . '/yak-account-' . bin2hex(random_bytes(8));
        File::ensureDirectoryExists($path, 0700);

        return $path;
    }
}
