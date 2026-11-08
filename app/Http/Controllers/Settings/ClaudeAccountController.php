<?php

namespace App\Http\Controllers\Settings;

use App\Enums\ClaudeAccountKind;
use App\Enums\ClaudeAccountStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\RotateClaudeAccountRequest;
use App\Http\Requests\Settings\StoreClaudeAccountRequest;
use App\Http\Requests\Settings\UpdateClaudeAccountRequest;
use App\Http\Resources\ClaudeAccountData;
use App\Models\ClaudeAccount;
use App\Services\ClaudeAccounts\ClaudeAccountValidator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ClaudeAccountController extends Controller
{
    public function index(Request $request): Response
    {
        $accounts = ClaudeAccount::query()
            ->where(fn ($query) => $query->whereNull('owner_user_id')->orWhere('owner_user_id', Auth::id()))
            ->orderBy('name')
            ->get();

        return Inertia::render('Settings/ClaudeAccounts', [
            'accounts' => $accounts->map(ClaudeAccountData::from(...))->all(),
            'models' => config('yak.threads.models', ['opus', 'sonnet', 'haiku']),
        ]);
    }

    public function store(StoreClaudeAccountRequest $request, ClaudeAccountValidator $validator): RedirectResponse
    {
        abort_if($request->boolean('is_system') && ! $this->managesSystemAccounts(), 403);

        $kind = ClaudeAccountKind::from($request->string('kind')->toString());
        $secret = $request->string('secret')->toString();

        $this->assertValid($validator, $kind, $secret);

        ClaudeAccount::query()->create([
            'owner_user_id' => $request->boolean('is_system') ? null : Auth::id(),
            'name' => $request->string('name')->toString(),
            'kind' => $kind,
            'secret' => $secret,
            'default_model' => $request->input('default_model'),
            'status' => ClaudeAccountStatus::Active,
            'validated_at' => now(),
            'expires_at' => $kind === ClaudeAccountKind::SetupToken ? now()->addYear() : null,
        ]);

        return redirect()->route('settings.claude-accounts')->with('success', 'Account added.');
    }

    public function rotate(RotateClaudeAccountRequest $request, ClaudeAccount $account, ClaudeAccountValidator $validator): RedirectResponse
    {
        $this->authorizeAccount($request, $account);
        $secret = $request->string('secret')->toString();

        $this->assertValid($validator, $account->kind, $secret);

        $account->update([
            'secret' => $secret,
            'status' => ClaudeAccountStatus::Active,
            'validated_at' => now(),
            'last_error' => null,
            'expires_at' => $account->kind === ClaudeAccountKind::SetupToken ? now()->addYear() : null,
        ]);

        return redirect()->route('settings.claude-accounts')->with('success', 'Secret replaced.');
    }

    public function update(UpdateClaudeAccountRequest $request, ClaudeAccount $account): RedirectResponse
    {
        $this->authorizeAccount($request, $account);

        $account->update($request->safe()->except('is_default'));

        if ($request->has('is_default')) {
            $request->boolean('is_default')
                ? $account->markDefaultFor($account->owner)
                : $account->update(['is_default' => false]);
        }

        return redirect()->route('settings.claude-accounts');
    }

    public function destroy(Request $request, ClaudeAccount $account): RedirectResponse
    {
        $this->authorizeAccount($request, $account);

        $account->delete();

        return redirect()->route('settings.claude-accounts')->with('success', 'Account removed.');
    }

    private function assertValid(ClaudeAccountValidator $validator, ClaudeAccountKind $kind, string $secret): void
    {
        $validation = $validator->validate($kind, $secret);

        if (! $validation->valid) {
            throw ValidationException::withMessages(['secret' => $validation->error ?? 'Claude rejected the credential.']);
        }
    }

    /** A person manages their own accounts; only a system account admin manages the Yak-owned ones. */
    private function authorizeAccount(Request $request, ClaudeAccount $account): void
    {
        abort_unless($account->isSystem() ? $this->managesSystemAccounts() : $account->owner_user_id === Auth::id(), 403);
    }

    private function managesSystemAccounts(): bool
    {
        return in_array(Auth::id(), config('yak.system_claude_account_admin_ids', []), true);
    }
}
