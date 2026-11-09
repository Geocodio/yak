<?php

namespace App\Console\Commands;

use App\Enums\ClaudeAccountColor;
use App\Enums\ClaudeAccountIcon;
use App\Enums\ClaudeAccountKind;
use App\Enums\ClaudeAccountStatus;
use App\Models\ClaudeAccount;
use App\Services\ClaudeAccounts\ClaudeAccountValidator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('yak:claude-account:add-system {name : A label for the account} {--kind=setup_token : setup_token or api_key} {--icon=sparkles : Lucide icon name} {--color=accent : Palette colour name} {--default : Make it the account unattended work bills to} {--from=php://stdin : File to read the secret from}')]
#[Description('Add a Yak-owned Claude account; the secret is read from standard input, never from an argument')]
class AddSystemClaudeAccountCommand extends Command
{
    public function handle(ClaudeAccountValidator $validator): int
    {
        $kind = ClaudeAccountKind::tryFrom((string) $this->option('kind'));

        if ($kind === null) {
            $this->error('The kind must be setup_token or api_key.');

            return self::FAILURE;
        }

        $icon = ClaudeAccountIcon::tryFrom((string) $this->option('icon'));
        $color = ClaudeAccountColor::tryFrom((string) $this->option('color'));

        if ($icon === null || $color === null) {
            $this->error('The icon or colour is not in the allowed set.');

            return self::FAILURE;
        }

        $secret = trim((string) @file_get_contents((string) $this->option('from')));

        if ($secret === '') {
            $this->error('Pipe the secret on standard input, for example: echo "$TOKEN" | php artisan yak:claude-account:add-system "Yak shared".');

            return self::FAILURE;
        }

        $validation = $validator->validate($kind, $secret);

        if (! $validation->valid) {
            $this->error('Claude rejected the credential: ' . ($validation->error ?? 'no reason given'));

            return self::FAILURE;
        }

        $account = ClaudeAccount::query()->create([
            'owner_user_id' => null,
            'name' => (string) $this->argument('name'),
            'kind' => $kind,
            'icon' => $icon,
            'color' => $color,
            'secret' => $secret,
            'status' => ClaudeAccountStatus::Active,
            'validated_at' => now(),
            'expires_at' => $kind === ClaudeAccountKind::SetupToken ? now()->addYear() : null,
        ]);

        if ($this->option('default') || ! ClaudeAccount::query()->whereNull('owner_user_id')->where('is_default', true)->exists()) {
            $account->markDefaultFor(null);
        }

        $this->info("Added the Yak account \"{$account->name}\"" . ($account->is_default ? ' as the default.' : '.'));

        return self::SUCCESS;
    }
}
