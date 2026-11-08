<?php

namespace App\Services\ClaudeAccounts;

use App\DataTransferObjects\AccountValidation;
use App\Enums\ClaudeAccountKind;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Proves a pasted credential works by running one Haiku turn on the host with
 * an environment that holds only that credential and throwaway directories.
 */
class ClaudeAccountValidator
{
    public function validate(ClaudeAccountKind $kind, string $secret): AccountValidation
    {
        $homeDirectory = $this->makeTemporaryDirectory();
        $configDirectory = $this->makeTemporaryDirectory();

        try {
            $result = Process::timeout(120)
                ->env($this->environment($kind, $secret, $homeDirectory, $configDirectory))
                ->run(['claude', '-p', '--model', 'haiku', '--max-turns', '1', '--output-format', 'json', 'Reply with OK']);

            if ($result->failed()) {
                return new AccountValidation(false, $this->describeFailure($result->output() . "\n" . $result->errorOutput(), $secret));
            }

            $decoded = json_decode($result->output(), true);

            if (is_array($decoded) && ($decoded['is_error'] ?? false) === false) {
                return new AccountValidation(true);
            }

            return new AccountValidation(false, $this->describeFailure((string) ($decoded['result'] ?? $result->output()), $secret));
        } catch (Throwable $exception) {
            return new AccountValidation(false, $this->describeFailure($exception->getMessage(), $secret));
        } finally {
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
    private function environment(ClaudeAccountKind $kind, string $secret, string $homeDirectory, string $configDirectory): array
    {
        $cleared = array_fill_keys(array_keys(getenv()), false);

        return [
            ...$cleared,
            'PATH' => (string) getenv('PATH'),
            'HOME' => $homeDirectory,
            'CLAUDE_CONFIG_DIR' => $configDirectory,
            $kind === ClaudeAccountKind::ApiKey ? 'ANTHROPIC_API_KEY' : 'CLAUDE_CODE_OAUTH_TOKEN' => $secret,
        ];
    }

    private function describeFailure(string $output, string $secret): string
    {
        $message = trim(str_replace($secret, '[redacted]', $output));

        return $message === '' ? 'Claude rejected the credential.' : mb_substr($message, 0, 300);
    }

    private function makeTemporaryDirectory(): string
    {
        $path = sys_get_temp_dir() . '/yak-account-' . bin2hex(random_bytes(8));
        File::ensureDirectoryExists($path, 0700);

        return $path;
    }
}
