<?php

use App\Jobs\ResearchYakJob;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\RepositoryRiskProfiles;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->profiles = app(RepositoryRiskProfiles::class);
    $this->content = ['areas' => [[
        'name' => 'Billing', 'paths' => ['app/Billing/**'], 'symbols' => ['Invoice::charge'],
        'risk' => 'critical', 'rationale' => 'Charges customers.', 'evidence' => ['app/Billing/Invoice.php:42'],
    ]], 'unknowns' => []];
});

it('keeps AI drafts inactive until a human approves the exact hash', function () {
    $draft = $this->profiles->draft('acme/api', str_repeat('a', 40), json_encode($this->content));
    expect($this->profiles->active('acme/api'))->toBeNull();
    $this->profiles->approve('acme/api', $draft['version'], 'Sylvester');
    expect($this->profiles->active('acme/api')['version'])->toBe($draft['version'])
        ->and($this->profiles->active('acme/api')['approved_by'])->toBe('Sylvester');
});

it('rejects approval of a modified draft', function () {
    $draft = $this->profiles->draft('acme/api', str_repeat('a', 40), json_encode($this->content));
    $draft['areas'][0]['risk'] = 'low';
    Storage::disk('local')->put($this->profiles->directory('acme/api') . '/drafts/' . $draft['version'] . '.json', json_encode($draft));
    $this->profiles->approve('acme/api', $draft['version'], 'Sylvester');
})->throws(RuntimeException::class, 'mismatch');

it('does not accept model-supplied approval metadata', function () {
    $content = $this->content + ['approved_by' => 'AI', 'approved_at' => now()->toIso8601String()];
    $draft = $this->profiles->draft('acme/api', str_repeat('a', 40), json_encode($content));
    expect($draft)->not->toHaveKey('approved_by')->and($this->profiles->active('acme/api'))->toBeNull();
});

it('expires old profiles and preserves approved versions', function () {
    $draft = $this->profiles->draft('acme/api', str_repeat('a', 40), json_encode($this->content));
    $this->profiles->approve('acme/api', $draft['version'], 'Sylvester');
    $this->travel(91)->days();
    expect($this->profiles->active('acme/api'))->toBeNull();
    Storage::disk('local')->assertExists($this->profiles->directory('acme/api') . '/approved/' . $draft['version'] . '.json');
});

it('dispatches profile generation through the research queue', function () {
    Queue::fake();
    Repository::factory()->create(['slug' => 'acme/api', 'is_active' => true]);
    $this->artisan('yak:risk-profile acme/api')->assertSuccessful();
    Queue::assertPushed(ResearchYakJob::class);
    $task = YakTask::where('repo', 'acme/api')->first();
    expect(json_decode($task->context, true)['risk_profile_draft'])->toBeTrue()
        ->and($this->profiles->active('acme/api'))->toBeNull();
});
