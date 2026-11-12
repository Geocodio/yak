<?php

use App\Ai\Agents\ConcernClassifier;
use App\Enums\TaskMode;
use App\Jobs\SendCoOwnerMessagesJob;
use App\Models\CoOwnerRequest;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\CoOwner\CoOwnerGate;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Ai;

function concernsGateConfig(): string
{
    return "version: 1\nco_owner_gate:\n  mode: enforce\n";
}

function concernsGateCompare(array $files): array
{
    return ['api.github.com/repos/o/r/compare/*' => Http::response(['files' => $files])];
}

function gatePatched(array $names): array
{
    return array_map(fn (string $name): array => ['filename' => $name, 'status' => 'modified', 'additions' => 2, 'deletions' => 1, 'patch' => '@@ -1 +1 @@'], $names);
}

function fakeGateClassifier(array $touched, string $kind = 'no_runtime_change', bool $isUncertain = false): void
{
    Ai::fakeAgent(ConcernClassifier::class, array_fill(0, 2, ['touched_concerns' => $touched, 'change_kind' => $kind, 'is_uncertain' => $isUncertain]));
}

beforeEach(function () {
    configureFakeGitHubApp();
    Bus::fake([SendCoOwnerMessagesJob::class]);
    $this->repository = Repository::factory()->create(['github_full_name' => 'o/r', 'default_branch' => 'main']);
    $this->task = YakTask::factory()->awaitingCi()->create(['repo' => $this->repository->slug, 'branch_name' => 'yak/x', 'started_by_user_id' => null, 'source' => 'slack', 'mode' => TaskMode::Fix]);
    fakeYakFiles(['config.yml' => concernsGateConfig(), 'risk-profile.yml' => concernsYaml()]);
});

it('holds a floor path per path and skips the classifier', function () {
    Ai::fakeAgent(ConcernClassifier::class, []);
    Http::fake(gitHubTokenFake() + concernsGateCompare(gatePatched(['.github/workflows/ci.yml', 'src/a.php'])));

    $request = app(CoOwnerGate::class)->hold($this->task, $this->repository);

    expect($request->hold_reasons)->toBe(['floor'])
        ->and(collect($request->changed_files)->pluck('isHeld', 'path')->all())->toBe(['.github/workflows/ci.yml' => true, 'src/a.php' => false]);
    Ai::assertAgentNotPrompted(ConcernClassifier::class, fn () => true);
});

it('holds every file when a concern is touched', function () {
    fakeGateClassifier([['name' => 'Customer charges and Stripe writes', 'evidence' => 'charges']]);
    Http::fake(gitHubTokenFake() + concernsGateCompare(gatePatched(['src/a.php', 'src/b.php'])));

    $request = app(CoOwnerGate::class)->hold($this->task, $this->repository);

    expect($request->hold_reasons)->toBe(['concern'])
        ->and($request->matched_areas[0]['name'])->toBe('Customer charges and Stripe writes')
        ->and(collect($request->changed_files)->pluck('isHeld')->all())->toBe([true, true]);
});

it('passes a clear change', function () {
    fakeGateClassifier([]);
    Http::fake(gitHubTokenFake() + concernsGateCompare(gatePatched(['src/a.php'])));

    expect(app(CoOwnerGate::class)->hold($this->task, $this->repository))->toBeNull()
        ->and(CoOwnerRequest::count())->toBe(0);
});

it('holds an uncertain classification', function () {
    fakeGateClassifier([], isUncertain: true);
    Http::fake(gitHubTokenFake() + concernsGateCompare(gatePatched(['src/a.php'])));

    expect(app(CoOwnerGate::class)->hold($this->task, $this->repository)->hold_reasons)->toBe(['concern']);
});

it('never prompts the classifier for an empty concern list', function () {
    fakeYakFiles(['config.yml' => concernsGateConfig(), 'risk-profile.yml' => "version: 2\nconcerns: []\n"]);
    Ai::fakeAgent(ConcernClassifier::class, []);
    Http::fake(gitHubTokenFake() + concernsGateCompare(gatePatched(['src/a.php'])));

    expect(app(CoOwnerGate::class)->hold($this->task, $this->repository))->toBeNull();
    Ai::assertAgentNotPrompted(ConcernClassifier::class, fn () => true);
});

it('holds everything when the compare listing is at the cap, without classifying', function () {
    Ai::fakeAgent(ConcernClassifier::class, []);
    Http::fake(gitHubTokenFake() + concernsGateCompare(gatePatched(array_map(fn (int $i): string => "src/f{$i}.php", range(1, 300)))));

    $request = app(CoOwnerGate::class)->hold($this->task, $this->repository);

    expect($request->hold_reasons)->toBe(['listing'])
        ->and(collect($request->changed_files)->every(fn (array $file): bool => $file['isHeld']))->toBeTrue();
    Ai::assertAgentNotPrompted(ConcernClassifier::class, fn () => true);
});

it('caps concern evidence at 300 characters', function () {
    fakeGateClassifier([['name' => 'Customer charges and Stripe writes', 'evidence' => str_repeat('x', 500)]]);
    Http::fake(gitHubTokenFake() + concernsGateCompare(gatePatched(['src/a.php'])));

    $request = app(CoOwnerGate::class)->hold($this->task, $this->repository);

    expect(mb_strlen($request->matched_areas[0]['rationale']))->toBeLessThanOrEqual(300);
});
