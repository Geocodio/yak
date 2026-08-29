import { test, beforeEach } from 'node:test';
import assert from 'node:assert';
import { mkdtempSync, writeFileSync, readFileSync, chmodSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { startSession, readSession, writeSession } from '../src/lib/session.ts';
import { writeInitialStoryboard } from '../src/lib/storyboard.ts';
import { emitAutoEvents } from '../src/lib/autoEvents.ts';
import { runPassthrough } from '../src/commands/passthrough.ts';

const CLI_ENTRY = fileURLToPath(new URL('../src/index.ts', import.meta.url));

async function runCli(argv: string[]): Promise<{ status: number | null; stdout: string; stderr: string }> {
  const result = spawnSync(process.execPath, ['--import', 'tsx', CLI_ENTRY, ...argv], {
    encoding: 'utf8',
  });
  return { status: result.status, stdout: result.stdout ?? '', stderr: result.stderr ?? '' };
}

let dir: string;
function events() {
  return JSON.parse(readFileSync(join(dir, 'storyboard.json'), 'utf8')).events;
}
function setupSession(opts?: { callout?: number; emphasize?: number }) {
  startSession(dir, {
    storyboardPath: join(dir, 'storyboard.json'),
    calloutBudget: opts?.callout ?? 0,
    emphasizeBudget: opts?.emphasize ?? 0,
  });
  writeInitialStoryboard(join(dir, 'storyboard.json'), {
    tier: 'reviewer',
    goal: '',
    chapters: [],
    expected_duration_seconds: 30,
    emphasize_budget: opts?.emphasize ?? 0,
    callout_budget: opts?.callout ?? 0,
    fastforward_segments: [],
  });
}

beforeEach(() => {
  dir = mkdtempSync(join(tmpdir(), 'yb-cmd-'));
});

test('legacy annotation commands fall through to passthrough', async () => {
  const help = await runCli(['--help']);
  for (const command of ['record start', 'plan', 'chapter', 'narrate', 'note', 'callout', 'emphasize', 'fastforward']) {
    assert.ok(!help.stdout.includes(command), `help still advertises ${command}`);
  }
});

// -------- auto events --------
test('click command emits click event', () => {
  setupSession();
  emitAutoEvents(dir, ['click', '--x', '420', '--y', '180']);
  assert.deepStrictEqual(
    { type: events()[0].type, x: events()[0].x, y: events()[0].y },
    { type: 'click', x: 420, y: 180 }
  );
});

test('click consumes pending emphasize', () => {
  setupSession({ emphasize: 1 });
  const s = readSession(dir)!;
  s.emphasizePending = true;
  writeSession(dir, s);
  assert.strictEqual(readSession(dir)!.emphasizePending, true);
  emitAutoEvents(dir, ['click', '--x', '10', '--y', '20']);
  assert.strictEqual(readSession(dir)!.emphasizePending, false);
});

test('type with modifier emits keypress event', () => {
  setupSession();
  emitAutoEvents(dir, ['type', 'cmd+k']);
  assert.strictEqual(events()[0].type, 'keypress');
  assert.strictEqual(events()[0].keys, 'cmd+k');
});

test('type with special key emits keypress event', () => {
  setupSession();
  emitAutoEvents(dir, ['type', 'Enter']);
  assert.strictEqual(events()[0].type, 'keypress');
});

test('type with plain text does NOT emit keypress event', () => {
  setupSession();
  emitAutoEvents(dir, ['type', 'hello world']);
  assert.strictEqual(events().length, 0);
});

test('navigate emits navigate event', () => {
  setupSession();
  emitAutoEvents(dir, ['navigate', 'https://example.com/tasks']);
  assert.strictEqual(events()[0].type, 'navigate');
});

test('unrelated command emits nothing', () => {
  setupSession();
  emitAutoEvents(dir, ['screenshot', '/tmp/x.png']);
  assert.strictEqual(events().length, 0);
});

test('emitAutoEvents is no-op when no session', () => {
  // does not throw
  emitAutoEvents(dir, ['click', '--x', '1', '--y', '1']);
});

// -------- passthrough --------
test('passthrough forwards to agent-browser and returns its exit code', () => {
  const shim = join(dir, 'agent-browser');
  writeFileSync(shim, '#!/bin/sh\necho "got $@"\nexit 42\n');
  chmodSync(shim, 0o755);
  const code = runPassthrough({
    argv: ['screenshot', '/tmp/x.png'],
    agentBrowserPath: shim,
    artifactsDir: dir,
  });
  assert.strictEqual(code, 42);
});

test('passthrough emits a navigate event when a click changes the URL', () => {
  setupSession();
  const shim = join(dir, 'agent-browser');
  // Shim answers "get url" with different URLs on successive calls (emulating
  // a click that caused navigation) and succeeds for every other command.
  writeFileSync(
    shim,
    `#!/bin/sh
STATE=${JSON.stringify(join(dir, '.shim-state'))}
N=$(cat "$STATE" 2>/dev/null || echo 0)
if [ "$1" = "get" ] && [ "$2" = "url" ]; then
  if [ "$N" = "0" ]; then
    echo "https://app.example/before"
    echo 1 > "$STATE"
  else
    echo "https://app.example/after"
  fi
  exit 0
fi
exit 0
`,
  );
  chmodSync(shim, 0o755);
  const code = runPassthrough({
    argv: ['click', '--selector', 'a.billing'],
    agentBrowserPath: shim,
    artifactsDir: dir,
  });
  assert.strictEqual(code, 0);
  const evs = events();
  const click = evs.find((e: { type: string }) => e.type === 'click');
  const nav = evs.find((e: { type: string }) => e.type === 'navigate');
  assert.ok(click, 'click event should be emitted');
  assert.ok(nav, 'navigate event should be auto-emitted');
  assert.strictEqual(nav.url, 'https://app.example/after');
});

test('passthrough does not emit navigate when URL is unchanged', () => {
  setupSession();
  const shim = join(dir, 'agent-browser');
  writeFileSync(
    shim,
    `#!/bin/sh
if [ "$1" = "get" ] && [ "$2" = "url" ]; then
  echo "https://app.example/same"
  exit 0
fi
exit 0
`,
  );
  chmodSync(shim, 0o755);
  runPassthrough({
    argv: ['click', '--selector', 'button.noop'],
    agentBrowserPath: shim,
    artifactsDir: dir,
  });
  const nav = events().find((e: { type: string }) => e.type === 'navigate');
  assert.strictEqual(nav, undefined);
});
