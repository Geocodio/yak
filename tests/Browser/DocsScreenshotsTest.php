<?php

/**
 * Generates the user-docs screenshots from dummy data. Run: DOCS_SCREENSHOTS=1 php -d memory_limit=2048M vendor/bin/pest tests/Browser/DocsScreenshotsTest.php
 * The PNGs land in tests/Browser/Screenshots/ (git-ignored); copy the screenshot-*.png files into docs/.
 * Skipped unless DOCS_SCREENSHOTS=1 is set; belongs to the docs-screenshots group.
 */

use App\Enums\RestoreStepName;
use App\Enums\RestoreStepStatus;
use App\Enums\RunnerState;
use App\Enums\StackThreadMessageKind;
use App\Enums\ThreadBlockReason;
use App\Enums\ThreadSandboxState;
use App\Enums\TurnMessageState;
use App\Enums\TurnState;
use App\Models\ClaudeAccount;
use App\Models\GithubKeySync;
use App\Models\PushDevice;
use App\Models\Repository;
use App\Models\SshKey;
use App\Models\StackThreadMessage;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadDiff;
use App\Models\ThreadDiffFile;
use App\Models\ThreadRepository;
use App\Models\ThreadRestoreStep;
use App\Models\ThreadTurnMessage;
use App\Models\User;
use App\Models\UserRepositorySetting;
use App\Models\YakTask;
use App\Services\Threads\ThreadEventPublisher;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses()->group('docs-screenshots');

beforeEach(function (): void {
    if (getenv('DOCS_SCREENSHOTS') !== '1') {
        $this->markTestSkipped('Set DOCS_SCREENSHOTS=1 to generate the docs screenshots.');
    }

    $this->ada = User::factory()->create(['name' => 'Ada Lovelace', 'email' => 'ada@example.com']);
    $this->grace = User::factory()->create(['name' => 'Grace Hopper', 'email' => 'grace@example.com']);
    $this->personal = ClaudeAccount::factory()->default()->create(['owner_user_id' => $this->ada->id, 'name' => 'Personal', 'validated_at' => now()]);
    $this->team = ClaudeAccount::factory()->create(['owner_user_id' => $this->ada->id, 'name' => 'Team', 'validated_at' => now(), 'color' => 'teal']);
    foreach (['acme/storefront', 'acme/api', 'acme/docs'] as $slug) {
        Repository::factory()->create(['slug' => $slug, 'name' => $slug, 'github_full_name' => $slug, 'is_default' => $slug === 'acme/storefront', 'ci_system' => 'github_actions', 'git_url' => "https://github.com/{$slug}.git", 'description' => null]);
    }
    $this->actingAs($this->ada);
    Queue::fake();
});

/**
 * A 1440x900 light-mode page.
 */
function docsPage(string $url): mixed
{
    return visit($url)->on()->macbookAir()->inLightMode();
}

function docsShot(mixed $page, string $name, ?string $selector = null, float $settle = 2, bool $isScrolledToEnd = false): void
{
    expect($page)->not->toBeNull();
    $page->wait($settle);
    if ($isScrolledToEnd) {
        $page->script("document.querySelectorAll('*').forEach((element) => { if (element.scrollHeight > element.clientHeight + 40 && ['auto', 'scroll'].includes(getComputedStyle(element).overflowY)) { element.scrollTop = element.scrollHeight; } })");
        $page->wait(0.5);
    }
    $selector === null ? $page->screenshot(fullPage: false, filename: $name) : $page->screenshotElement($selector, $name);
}

function docsAllowGitHub(): void
{
    $keyPair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($keyPair, $privateKey);
    config()->set('yak.channels.github.app_id', '999');
    config()->set('yak.channels.github.private_key', $privateKey);
    config()->set('yak.channels.github.installation_id', 42);
    Http::fake([
        'api.github.com/app/installations/42/access_tokens' => fn (Request $request) => Http::response(['token' => 'ghs_x', 'expires_at' => now()->addHour()->toIso8601String()], 201),
        'api.github.com/repos/*' => Http::response(['description' => null, 'topics' => []]),
    ]);
}

/**
 * A running thread on acme/storefront owned by Ada.
 *
 * @param  array<string, mixed>  $attributes
 */
function docsThread(array $attributes = []): Thread
{
    $thread = Thread::factory()->create([
        'name' => 'fix-flaky-checkout-test',
        'title' => 'Fix the flaky checkout test',
        'repo' => 'acme/storefront',
        'branch' => 'yak/fix-flaky-checkout-test',
        'created_by_user_id' => test()->ada->id,
        'owner_user_id' => test()->ada->id,
        'claude_account_id' => test()->personal->id,
        'preview_hostname' => 'fix-flaky-checkout.example.com',
        'sandbox_container' => 'yak-thread-1001',
        'sandbox_state' => ThreadSandboxState::Running,
        'sandbox_state_changed_at' => now(),
        'runner_state' => RunnerState::Idle,
        'runner_generation' => 1,
        ...$attributes,
    ]);
    ThreadRepository::factory()->for($thread)->create(['repo' => 'acme/storefront']);

    return $thread;
}

function docsConversation(Thread $thread, bool $isShort = false, bool $isCsv = false): void
{
    $prompt = $isCsv
        ? 'Add CSV export to the orders page. Reuse the filters from the list and stream the file from the API so large exports do not time out.'
        : 'The checkout test in tests/checkout.spec.ts fails about one run in five on CI. Find out why and make it stable.';
    $answer = $isCsv
        ? "I added an export endpoint to acme/api that streams the filtered orders as CSV, and an Export CSV button on the storefront orders page that calls it. Both test suites pass.\n\nReady for you to review the diffs."
        : "The test clicked Pay before the order summary finished loading, and a fixed 500 ms sleep only hid that most of the time. I replaced the sleep with a wait for the receipt element. The test now passes 20 runs in a row.\n\nReady for you to review the diff.";
    $task = YakTask::factory()->create(['thread_id' => $thread->id, 'description' => $prompt]);
    $run = TaskRun::factory()->create(['yak_task_id' => $task->id, 'thread_id' => $thread->id, 'turn_state' => TurnState::Completed, 'driver_user_id' => test()->ada->id, 'model' => 'sonnet', 'started_at' => now()->subMinutes(20)]);
    ThreadTurnMessage::factory()->create(['task_run_id' => $run->id, 'uuid' => 'u-1', 'state' => TurnMessageState::Completed]);
    $events = app(ThreadEventPublisher::class);
    $events->append($thread->id, 'entry', ['role' => 'system', 'subtype' => 'init', 'model' => 'claude-sonnet-5-5']);
    if ($isShort) {
        return;
    }

    $events->append($thread->id, 'entry', ['role' => 'assistant', 'messageId' => 'm1', 'blocks' => [
        ['type' => 'text', 'text' => "I'll start by reading the test and the helper it waits on."],
        ['type' => 'tool_use', 'id' => 't1', 'name' => 'Read', 'input' => ['file_path' => 'tests/checkout.spec.ts']],
    ]]);
    $events->append($thread->id, 'entry', ['role' => 'tool_result', 'toolUseId' => 't1', 'isError' => false, 'text' => "test('pays with a saved card', async () => {\n  await page.click('#pay');\n  expect(await page.textContent('.receipt')).toContain('Thank you');\n});"]);
    $events->append($thread->id, 'entry', ['role' => 'assistant', 'messageId' => 'm2', 'blocks' => [
        ['type' => 'tool_use', 'id' => 't2', 'name' => 'Grep', 'input' => ['pattern' => 'waitForTimeout', 'path' => 'tests']],
    ]]);
    $events->append($thread->id, 'entry', ['role' => 'tool_result', 'toolUseId' => 't2', 'isError' => false, 'text' => 'tests/checkout.spec.ts:14: await page.waitForTimeout(500);']);
    $events->append($thread->id, 'entry', ['role' => 'assistant', 'messageId' => 'm3', 'blocks' => [
        ['type' => 'tool_use', 'id' => 't3', 'name' => 'Edit', 'input' => ['file_path' => 'tests/checkout.spec.ts', 'old_string' => 'await page.waitForTimeout(500);', 'new_string' => "await page.waitForSelector('.receipt', { state: 'visible' });"]],
    ]]);
    $events->append($thread->id, 'entry', ['role' => 'tool_result', 'toolUseId' => 't3', 'isError' => false, 'text' => 'Edited tests/checkout.spec.ts']);
    $events->append($thread->id, 'entry', ['role' => 'assistant', 'messageId' => 'm4', 'blocks' => [
        ['type' => 'tool_use', 'id' => 't4', 'name' => 'Bash', 'input' => ['command' => 'npx playwright test tests/checkout.spec.ts --repeat-each 20']],
    ]]);
    $events->append($thread->id, 'entry', ['role' => 'tool_result', 'toolUseId' => 't4', 'isError' => false, 'text' => '20 passed (41s)']);
    $events->append($thread->id, 'entry', ['role' => 'assistant', 'messageId' => 'm5', 'blocks' => [
        ['type' => 'text', 'text' => $answer],
    ]]);
}

/**
 * @param  list<array{string, string, int, int, string}>  $files  path, status label, additions, deletions, patch
 */
function docsDiff(Thread $thread, array $files, ?int $repositoryRowId = null): ThreadDiff
{
    $diff = ThreadDiff::factory()->for($thread)->create(['files_count' => count($files), 'additions' => array_sum(array_column($files, 2)), 'deletions' => array_sum(array_column($files, 3)), 'base_sha' => str_repeat('a1', 20), 'head_sha' => str_repeat('b2', 20)]);
    foreach ($files as [$path, , $additions, $deletions, $patch]) {
        ThreadDiffFile::factory()->for($diff, 'diff')->create(['path' => $path, 'additions' => $additions, 'deletions' => $deletions, 'patch' => $patch]);
    }

    return $diff;
}

const DOCS_CHECKOUT_PATCH = "@@ -10,6 +10,7 @@ test('pays with a saved card', async () => {\n   await page.goto('/checkout');\n   await page.click('#saved-card');\n   await page.click('#pay');\n-  await page.waitForTimeout(500);\n-  expect(await page.textContent('.receipt')).toContain('Thank you');\n+  await page.waitForSelector('.receipt', { state: 'visible' });\n+  const receipt = await page.textContent('.receipt');\n+  expect(receipt).toContain('Thank you');\n });\n";

it('01 thread page', function () {
    $thread = docsThread();
    docsConversation($thread);
    docsDiff($thread, [['tests/checkout.spec.ts', 'modified', 3, 2, DOCS_CHECKOUT_PATCH]]);

    $page = docsPage(route('threads.show', $thread))->click('[data-testid="thread-panel-tab-environment"]');
    docsShot($page, 'screenshot-thread-page');
});

it('02 new thread', function () {
    docsAllowGitHub();
    $page = docsPage(route('threads.new'))
        ->type('[data-testid="start-thread-description"]', 'Add CSV export to the orders page. Reuse the filters from the list and stream the file so large exports do not time out.')
        ->click('[data-testid="add-repo"]')
        ->click('acme/api');
    docsShot($page, 'screenshot-new-thread');
});

it('03 thread guard', function () {
    $thread = docsThread();
    docsConversation($thread);
    $task = YakTask::factory()->create(['thread_id' => $thread->id, 'description' => 'Fix the flaky checkout test']);
    TaskRun::factory()->create(['yak_task_id' => $task->id, 'thread_id' => $thread->id, 'turn_state' => TurnState::StoppedByGuard, 'started_at' => now()->subHour()]);

    docsShot(docsPage(route('threads.show', $thread)), 'screenshot-thread-guard');
});

it('04 thread changes', function () {
    $thread = docsThread();
    docsConversation($thread);
    $diff = docsDiff($thread, [
        ['tests/checkout.spec.ts', 'modified', 3, 2, DOCS_CHECKOUT_PATCH],
        ['tests/helpers/wait-for-order.ts', 'modified', 6, 0, "@@ -1,3 +1,7 @@\n import { Page } from '@playwright/test';\n \n+export async function waitForOrderSummary(page: Page): Promise<void> {\n+  await page.waitForSelector('[data-testid=\"order-summary\"]', { state: 'visible' });\n+}\n+\n export const CHECKOUT_URL = '/checkout';\n"],
    ]);
    $page = docsPage(route('threads.show', $thread))->click('[data-testid="thread-panel-tab-changes"]')
        ->click('[data-testid="changes-file-tests/checkout.spec.ts"] button');
    docsShot($page, 'screenshot-thread-changes');
});

it('05 thread share', function () {
    $thread = docsThread(['visibility' => 'private']);
    docsConversation($thread);
    $page = docsPage(route('threads.show', $thread))
        ->click('[data-testid="share-button"]')
        ->click('[data-testid="share-mode-people"]')
        ->click('[data-testid="share-person-' . $this->grace->id . '"]');
    docsShot($page, 'screenshot-thread-share', '[data-testid="share-dialog"]', 1);
});

it('06 thread rebuild failed', function () {
    $thread = docsThread(['sandbox_state' => ThreadSandboxState::Starting, 'blocked_reason' => ThreadBlockReason::RestoreFailed]);
    docsConversation($thread, true);
    $steps = [RestoreStepName::CreateSandbox, RestoreStepName::CheckoutBranch, RestoreStepName::RunSetupScript, RestoreStepName::RunRefreshTriggers, RestoreStepName::RestoreSessionFiles, RestoreStepName::PrepareEnvironment];
    foreach ($steps as $index => $step) {
        $factory = ThreadRestoreStep::factory();
        $factory = match (true) {
            $index < 2 => $factory->state(['status' => RestoreStepStatus::Succeeded, 'started_at' => now()->subSeconds(30), 'finished_at' => now()->subSeconds(26)]),
            $index === 2 => $factory->failed('Setup script failed with exit code 127')->state(['output' => "npm ci\nsh: 1: composer: not found"]),
            default => $factory,
        };
        $factory->create(['thread_id' => $thread->id, 'position' => $index + 1, 'step' => $step]);
    }

    docsShot(docsPage(route('threads.show', $thread)), 'screenshot-thread-rebuild', null, 2, true);
});

it('07 multi repo picker', function () {
    docsAllowGitHub();
    $page = docsPage(route('threads.new'))
        ->type('[data-testid="start-thread-description"]', 'Add a bulk status endpoint to the API and use it from the storefront order list. Check the docs for the response conventions.')
        ->click('[data-testid="add-repo"]')
        ->click('acme/api')
        ->click('[data-testid="add-repo"]')
        ->click('acme/docs')
        ->click('[data-testid="repo-chip"]:has-text("acme/docs") [data-testid="repo-role"]')
        ->click('Context');
    docsShot($page, 'screenshot-multi-repo-picker');
});

it('08 pull requests panel', function () {
    $thread = docsThread(['title' => 'Add CSV export to orders', 'branch' => 'yak/add-csv-export-to-orders']);
    docsConversation($thread, false, true);
    $thread->repositories()->delete();
    ThreadRepository::factory()->for($thread)->withPullRequest(214)->create(['repo' => 'acme/storefront', 'pull_request_title' => 'Add an Export CSV button to the orders page', 'ci_status' => 'passing', 'merge_position' => 2, 'merge_reason' => 'The button calls the new export endpoint.']);
    ThreadRepository::factory()->for($thread)->changes()->withPullRequest(97)->create([
        'repo' => 'acme/api',
        'pull_request_title' => 'Stream order exports as CSV',
        'ci_status' => 'failing',
        'merge_position' => 1,
        'ci_checks' => [
            ['name' => 'tests', 'state' => 'failing', 'durationSeconds' => 142, 'excerpt' => "1) OrderExportTest::test_streams_large_exports\nExpected header row 'id,total,status' but got 'id,status,total'"],
            ['name' => 'lint', 'state' => 'passing', 'durationSeconds' => 24, 'excerpt' => null],
        ],
    ]);

    $page = docsPage(route('threads.show', $thread))->click('[data-testid="thread-panel-tab-pull-requests"]');
    docsShot($page, 'screenshot-pull-requests-panel');
});

it('09 multi repo changes', function () {
    $thread = docsThread(['title' => 'Add CSV export to orders', 'branch' => 'yak/add-csv-export-to-orders']);
    docsConversation($thread, false, true);
    $thread->repositories()->delete();
    ThreadRepository::factory()->for($thread)->create(['repo' => 'acme/storefront']);
    ThreadRepository::factory()->for($thread)->changes()->create(['repo' => 'acme/api']);
    ThreadRepository::factory()->for($thread)->context()->create(['repo' => 'acme/docs']);
    $diff = docsDiff($thread, [['src/pages/orders.tsx', 'modified', 12, 1, "@@ -20,2 +20,4 @@ export function OrdersPage() {\n   const { orders } = useOrders(filters);\n+  const exportUrl = '/api/orders/export?' + toQuery(filters);\n+\n   return (\n"]]);
    $api = $diff->repositories()->create(['repo' => 'acme/api', 'role' => 'changes', 'base_sha' => 'a', 'head_sha' => 'b', 'files_count' => 1, 'additions' => 34]);
    ThreadDiffFile::factory()->for($diff, 'diff')->create(['path' => 'app/Http/Controllers/OrderExportController.php', 'thread_diff_repository_id' => $api->id, 'additions' => 34, 'deletions' => 0, 'patch' => "@@ -0,0 +1,6 @@\n+<?php\n+\n+class OrderExportController\n+{\n+    public function __invoke(): StreamedResponse {}\n+}\n"]);
    $docs = $diff->repositories()->create(['repo' => 'acme/docs', 'role' => 'context', 'base_sha' => 'a', 'head_sha' => 'b', 'files_count' => 1, 'additions' => 3, 'is_throwaway' => true]);
    ThreadDiffFile::factory()->for($diff, 'diff')->create(['path' => 'notes/export-conventions.md', 'thread_diff_repository_id' => $docs->id, 'additions' => 3, 'deletions' => 0, 'patch' => "@@ -0,0 +1,3 @@\n+# Export notes\n+Streamed CSV files use a header row.\n+Dates are ISO 8601.\n"]);

    $page = docsPage(route('threads.show', $thread))->click('[data-testid="thread-panel-tab-changes"]')->click('[data-testid="changes-tab-acme/docs"]');
    docsShot($page, 'screenshot-multi-repo-changes');
});

it('10 stack panel', function () {
    $parent = docsThread(['title' => 'Add CSV export to orders', 'name' => 'csv-export', 'branch' => 'yak/add-csv-export-to-orders', 'child_awake_limit' => 3]);
    docsConversation($parent, false, true);
    $working = Thread::factory()->child($parent)->create(['name' => 'streamer', 'title' => 'Stream the CSV from the API', 'current_step' => 'Running the export tests', 'branch' => 'yak/csv-export-stream']);
    $done = Thread::factory()->child($parent)->create(['name' => 'scout', 'title' => 'Scout the orders API', 'result_summary' => 'The orders API pages by cursor, 100 rows per page.']);
    $failed = Thread::factory()->child($parent)->create(['name' => 'builder', 'title' => 'Build the Export CSV button']);
    foreach ([$working, $done, $failed] as $child) {
        TaskRun::factory()->create(['yak_task_id' => YakTask::factory()->create(['thread_id' => $child->id, 'description' => $child->title])->id, 'thread_id' => $child->id, 'turn_state' => $child->is($working) ? TurnState::Running : TurnState::Completed]);
    }
    StackThreadMessage::factory()->create(['root_thread_id' => $parent->id, 'from_thread_id' => $done->id, 'to_thread_id' => $parent->id, 'kind' => StackThreadMessageKind::Result, 'text' => 'The orders API pages by cursor, 100 rows per page.', 'delivered_at' => now()]);
    StackThreadMessage::factory()->create(['root_thread_id' => $parent->id, 'from_thread_id' => $failed->id, 'to_thread_id' => $parent->id, 'kind' => StackThreadMessageKind::Failure, 'text' => 'Sub-thread builder failed before finishing: the sandbox ran out of disk.', 'delivered_at' => now()]);

    $page = docsPage(route('threads.show', $parent))->click('[data-testid="thread-panel-tab-stack"]');
    docsShot($page, 'screenshot-stack-panel');
});

it('11 thread preview', function () {
    config(['yak.deployments.hostname_suffix' => 'example.com']);
    $thread = docsThread();
    docsConversation($thread);
    Cache::put("thread-preview:state:{$thread->id}", 'ready');

    $page = docsPage(route('threads.show', $thread))->click('[data-testid="thread-dock-toggle-preview"]')->assertVisible('[data-testid="preview-panel"]');
    docsShot($page, 'screenshot-thread-preview');
});

it('12 ssh keys', function () {
    $this->ada->forceFill(['github_id' => 4242, 'github_login' => 'ada-lovelace', 'github_linked_at' => now()])->save();
    GithubKeySync::factory()->create(['user_id' => $this->ada->id, 'github_id' => 4242, 'github_login' => 'ada-lovelace']);
    SshKey::factory()->fromGithub()->create(['user_id' => $this->ada->id, 'name' => 'ada-lovelace laptop']);
    SshKey::factory()->fromGithub()->create(['user_id' => $this->ada->id, 'name' => 'ada-lovelace desktop']);
    SshKey::factory()->create(['user_id' => $this->ada->id, 'name' => 'Build server']);

    docsShot(docsPage(route('settings.ssh-keys')), 'screenshot-ssh-keys', null, 2, true);
});

it('13 terminal panel', function () {
    config()->set('yak.ssh.internal_token', 'secret-token');
    $thread = docsThread();
    docsConversation($thread);
    $page = docsPage(route('threads.show', $thread))->assertPresent('[data-testid="thread-header"]');
    $page->script(<<<'JS'
(() => {
    const record = (window.__ws = { sockets: [] });
    class FakeSocket {
        static OPEN = 1;
        constructor(url) {
            this.url = url; this.readyState = 0; this.binaryType = 'blob'; record.sockets.push(this);
            setTimeout(() => { this.readyState = 1; this.onopen && this.onopen({}); }, 0);
        }
        send() {}
        close() { this.readyState = 3; this.onclose && this.onclose({ code: 1000 }); }
    }
    const RealWebSocket = window.WebSocket;
    window.WebSocket = new Proxy(RealWebSocket, { construct: (target, args) => (String(args[0]).includes('/terminal/ws') ? new FakeSocket(...args) : new target(...args)) });
})();
JS);
    $parts = parse_url($page->url());
    config()->set('yak.ssh.terminal_allowed_origins', [$parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '')]);
    $page->click('[data-testid="thread-dock-toggle-terminal"]')->wait(2);
    $terminalText = json_encode("ada@fix-flaky-checkout-test:/workspace\$ git status --short\r\n M tests/checkout.spec.ts\r\n M tests/helpers/wait-for-order.ts\r\nada@fix-flaky-checkout-test:/workspace\$ npx playwright test tests/checkout.spec.ts\r\nRunning 1 test using 1 worker\r\n  1 passed (3.2s)\r\nada@fix-flaky-checkout-test:/workspace\$ ");
    $page->script("window.__ws.sockets[0].onmessage({ data: new TextEncoder().encode({$terminalText}).buffer })");
    docsShot($page, 'screenshot-terminal-panel');
});

it('14 claude account add', function () {
    $page = docsPage(route('settings.claude-accounts'))->click('[data-testid="add-account"]')->wait(1);
    $page->type('[data-testid="account-secret"]', 'sk-ant-oat01-' . 'Hk3vQ9mT2xLpR7wZ4nBc8dYfJ6sUaE1gNoVt5iXrW0yKz');
    $page->type('input[placeholder="Geocodio work"]', 'Personal');
    $page->click('Make this my default account');
    docsShot($page, 'screenshot-claude-account-add', '[role="dialog"]', 1);
});

it('15 notifications', function () {
    config()->set('webpush.vapid.public_key', 'BEl62iUYgUivxIkv69yViEuiBIa-Ib9-SkvMeAtA3LFgDzkrxZJjSgSnfckjBJuBkr3qBUYIHBQFLXYp5Nksh8U');
    PushDevice::factory()->forUser($this->ada)->create(['label' => 'Chrome on macOS']);
    PushDevice::factory()->forUser($this->ada)->create(['label' => 'iPhone, installed app']);
    $page = docsPage(route('settings.notifications'))->wait(2);
    $page->script(<<<'JS'
document.querySelector('[data-testid="gate-off"]')?.remove();
const blocked = [...document.querySelectorAll('h4')].find((heading) => heading.textContent.includes('blocking notifications'));
let card = blocked;
while (card && !String(card.className).includes('border')) { card = card.parentElement; }
card?.remove();
document.querySelectorAll('.opacity-55').forEach((element) => element.classList.remove('opacity-55'));
JS);
    $page->script("document.querySelectorAll('*').forEach((element) => { if (element.scrollHeight > element.clientHeight + 40 && ['auto', 'scroll'].includes(getComputedStyle(element).overflowY)) { element.scrollTop = 150; } })");
    docsShot($page, 'screenshot-notifications', null, 1);
});

it('16 video theme', function () {
    docsShot(docsPage(route('settings.video')), 'screenshot-video-theme', null, 6);
});

it('17 repository page', function () {
    $repository = Repository::query()->where('slug', 'acme/storefront')->firstOrFail();
    $repository->update(['description' => 'The customer-facing web shop']);
    UserRepositorySetting::factory()->create(['user_id' => $this->ada->id, 'repo' => 'acme/storefront', 'claude_account_id' => $this->personal->id]);
    docsAllowGitHub();
    fakeYakFiles(['config.yml' => "version: 1\ndescription: The customer-facing web shop\n"]);

    $page = docsPage(route('repos.edit', $repository))->resize(1440, 1700);
    docsShot($page, 'screenshot-repository-page', null, 3);
});

afterAll(function (): void {
    if (getenv('DOCS_SCREENSHOTS') !== '1') {
        return;
    }

    foreach (glob(base_path('tests/Browser/Screenshots/screenshot-*.png')) ?: [] as $file) {
        copy($file, base_path('docs/' . basename($file)));
    }
});
