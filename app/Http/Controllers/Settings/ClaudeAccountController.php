<?php

namespace App\Http\Controllers\Settings;

use App\Enums\ClaudeAccountKind;
use App\Enums\ClaudeAccountStatus;
use App\Enums\RunnerState;
use App\Enums\TurnState;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\RotateClaudeAccountRequest;
use App\Http\Requests\Settings\StoreClaudeAccountRequest;
use App\Http\Requests\Settings\UpdateClaudeAccountRequest;
use App\Http\Resources\AwakeShareData;
use App\Http\Resources\ClaudeAccountData;
use App\Models\ClaudeAccount;
use App\Models\Thread;
use App\Services\ClaudeAccounts\ClaudeAccountValidator;
use App\Services\Threads\ThreadBlockClearer;
use App\Services\Threads\ThreadCommands;
use App\Services\Threads\ThreadTurns;
use App\Support\Docs;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ClaudeAccountController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', ClaudeAccount::class);

        $canManageYakAccounts = $request->user()?->can('create', [ClaudeAccount::class, true]) ?? false;

        return Inertia::render('Settings/ClaudeAccounts', [
            'accounts' => Inertia::defer(fn () => $this->present(ClaudeAccount::query()->where('owner_user_id', $request->user()?->id)), 'accounts'),
            'yakAccounts' => Inertia::defer(fn () => $this->present(ClaudeAccount::query()->whereNull('owner_user_id')), 'accounts'),
            'canManageYakAccounts' => $canManageYakAccounts,
            'awakeShare' => $canManageYakAccounts ? AwakeShareData::forAdmin() : null,
            'models' => config('claude-accounts.models'),
            'docsUrl' => Docs::url('accounts'),
        ]);
    }

    public function store(StoreClaudeAccountRequest $request, ClaudeAccountValidator $validator): RedirectResponse
    {
        $user = $request->user();
        $kind = ClaudeAccountKind::from($request->string('kind')->toString());
        $isSystem = $request->boolean('is_system');

        $this->assertValid($validator, $kind, $request->string('secret')->toString());

        $account = ClaudeAccount::query()->create([
            'owner_user_id' => $isSystem ? null : $user?->id,
            'name' => $request->string('name')->toString(),
            'kind' => $kind,
            'secret' => $request->string('secret')->toString(),
            'default_model' => $request->input('default_model'),
            ...$request->safe()->only(['icon', 'color']),
            'status' => ClaudeAccountStatus::Active,
            'validated_at' => now(),
            'expires_at' => $this->expiryFor($request, $kind),
        ]);

        $isFirstPersonalAccount = ! $isSystem
            && ClaudeAccount::query()->where('owner_user_id', $user?->id)->whereKeyNot($account->id)->doesntExist();

        if ($request->boolean('make_default') || $isFirstPersonalAccount) {
            $account->markDefaultFor($account->owner);
        }

        return redirect()->route('settings.claude-accounts')->with('success', 'Account added.');
    }

    public function rotate(RotateClaudeAccountRequest $request, ClaudeAccount $account, ClaudeAccountValidator $validator): RedirectResponse
    {
        $this->assertValid($validator, $account->kind, $request->string('secret')->toString());

        $account->forceFill([
            'secret' => $request->string('secret')->toString(),
            'status' => ClaudeAccountStatus::Active,
            'validated_at' => now(),
            'last_error' => null,
            'last_expiry_warning_at' => null,
            'expires_at' => $this->expiryFor($request, $account->kind),
        ])->save();

        app(ThreadBlockClearer::class)->clearForAccount($account);
        app(ThreadTurns::class)->resumeWaiting();

        return redirect()->route('settings.claude-accounts')->with('success', 'Secret replaced.');
    }

    public function update(UpdateClaudeAccountRequest $request, ClaudeAccount $account): RedirectResponse
    {
        $account->update($request->safe()->except('is_default'));

        if ($request->has('is_default')) {
            $request->boolean('is_default')
                ? $account->markDefaultFor($account->owner)
                : $account->update(['is_default' => false]);
        }

        return redirect()->route('settings.claude-accounts');
    }

    public function destroy(Request $request, ClaudeAccount $account, ThreadCommands $commands): RedirectResponse
    {
        Gate::authorize('manage', $account);

        $activeStates = [TurnState::Pending, TurnState::WaitingForAccount, TurnState::WaitingForSandbox, TurnState::Running];

        // A resting runner would otherwise stay warm on a secret that no longer exists.
        Thread::query()
            ->withoutVisibilityScope()
            ->where('claude_account_id', $account->id)
            ->where('runner_state', '!=', RunnerState::None)
            ->whereDoesntHave('runs', fn ($runs) => $runs->whereIn('turn_state', $activeStates))
            ->get()
            ->each(function (Thread $thread) use ($commands): void {
                $commands->kill($thread);
                $thread->forceFill(['runner_state' => RunnerState::None])->save();
            });

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

    private function expiryFor(Request $request, ClaudeAccountKind $kind): ?CarbonImmutable
    {
        if ($request->filled('expires_at')) {
            return CarbonImmutable::parse($request->string('expires_at')->toString());
        }

        return $kind === ClaudeAccountKind::SetupToken ? now()->addYear()->toImmutable() : null;
    }

    /**
     * @param  Builder<ClaudeAccount>  $query
     * @return list<array<string, mixed>>
     */
    private function present($query): array
    {
        return array_values($query->checked()->orderBy('name')->get()->map(fn (ClaudeAccount $account): array => ClaudeAccountData::from($account))->all());
    }
}
