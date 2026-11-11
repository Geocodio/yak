<?php

namespace App\Services\ClaudeAccounts;

use App\Channels\Slack\UserLookup;
use App\Mail\AccountProblemMail;
use App\Models\ClaudeAccount;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Tells people their Claude account needs attention with a Slack direct message
 * and an email.
 * The notification system is bound to a task, which an account event has none
 * of, so this sends the direct message itself and honours the per-user off
 * switch. Messages carry the account name and a link to Settings > Claude
 * accounts, never the kind, the secret, the error body or repository names.
 */
class AccountNotifier
{
    public function invalid(ClaudeAccount $account): void
    {
        $this->send($this->owners($account), "Claude account \"{$account->name}\" stopped working. Paste a new token to fix it: {$this->link()}");
    }

    public function usageLimit(ClaudeAccount $account, ?CarbonImmutable $resetsAt, ?User $driver): void
    {
        $when = $resetsAt !== null ? ' It resets ' . $resetsAt->diffForHumans() . '.' : '';

        $this->send(
            [...$this->owners($account), ...($driver !== null ? [$driver] : [])],
            "Claude account \"{$account->name}\" hit its usage limit.{$when} {$this->link()}",
        );
    }

    public function expiringSoon(ClaudeAccount $account): void
    {
        $this->send($this->owners($account), "Claude account \"{$account->name}\" expires soon. Replace its token before it stops working: {$this->link()}");
    }

    public function expired(ClaudeAccount $account): void
    {
        $this->send($this->owners($account), "Claude account \"{$account->name}\" has expired. Paste a new token to fix it: {$this->link()}");
    }

    /**
     * @return list<User>
     */
    private function owners(ClaudeAccount $account): array
    {
        if ($account->owner !== null) {
            return [$account->owner];
        }

        return array_values(User::query()->whereKey(config('claude-accounts.admin_user_ids'))->get()->all());
    }

    private function link(): string
    {
        return route('settings.claude-accounts');
    }

    /**
     * @param  list<User>  $recipients
     */
    private function send(array $recipients, string $text): void
    {
        $token = (string) config('yak.channels.slack.bot_token');

        foreach (collect($recipients)->unique('id') as $user) {
            $this->sendMail($user, $text);

            if (! $user->direct_messages_enabled) {
                continue;
            }

            $slackUserId = app(UserLookup::class)->slackUserIdFor($user);

            if ($slackUserId === null || $token === '') {
                continue;
            }

            try {
                $response = Http::withToken($token)->post('https://slack.com/api/chat.postMessage', ['channel' => $slackUserId, 'text' => $text]);

                if ($response->json('ok') !== true) {
                    Log::channel('yak')->warning('Claude account message was not delivered', ['user_id' => $user->id, 'error' => $response->json('error')]);
                }
            } catch (Throwable $exception) {
                Log::channel('yak')->warning('Claude account message was not delivered', ['user_id' => $user->id, 'error' => $exception->getMessage()]);
            }
        }
    }

    private function sendMail(User $user, string $text): void
    {
        try {
            Mail::to($user->email)->send(new AccountProblemMail($text));
        } catch (Throwable $exception) {
            Log::channel('yak')->warning('Claude account email was not delivered', ['user_id' => $user->id, 'error' => $exception->getMessage()]);
        }
    }
}
