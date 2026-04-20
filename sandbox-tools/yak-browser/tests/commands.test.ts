import { test, beforeEach } from 'node:test';
import assert from 'node:assert';
import { mkdtempSync, writeFileSync, readFileSync, chmodSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { startSession, readSession, writeSession, clearSession } from '../src/lib/session.ts';
import { writeInitialStoryboard } from '../src/lib/storyboard.ts';
import { emitAutoEvents } from '../src/lib/autoEvents.ts';
import { runPlan } from '../src/commands/plan.ts';
import { runChapter } from '../src/commands/chapter.ts';
import { runNarrate } from '../src/commands/narrate.ts';
import { runNote } from '../src/commands/note.ts';
import { runCallout } from '../src/commands/callout.ts';
import { runEmphasize } from '../src/commands/emphasize.ts';
import { runFastforward } from '../src/commands/fastforward.ts';
import { runPassthrough } from '../src/commands/passthrough.ts';

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

// -------- plan --------
const validPlan = {
  tier: 'reviewer',
  goal: 'demo',
  chapters: [
    { title: 'Intro', beats: [] },
    { title: 'Middle', beats: [] },
    { title: 'Result', beats: [] },
  ],
  expected_duration_seconds: 45,
  emphasize_budget: 2,
  callout_budget: 1,
  fastforward_segments: [],
};

test('plan with valid file initializes storyboard and session budgets', () => {
  startSession(dir, { storyboardPath: join(dir, 'storyboard.json') });
  const planFile = join(dir, 'plan.json');
  writeFileSync(planFile, JSON.stringify(validPlan));
  assert.strictEqual(runPlan({ artifactsDir: dir, planPath: planFile }), 0);
  const sb = JSON.parse(readFileSync(join(dir, 'storyboard.json'), 'utf8'));
  assert.deepStrictEqual(sb.plan, validPlan);
  const s = readSession(dir)!;
  assert.strictEqual(s.emphasizeBudget, 2);
  assert.strictEqual(s.calloutBudget, 1);
  assert.deepStrictEqual(
    s.chapters.map((c) => c.title),
    ['Intro', 'Middle', 'Result']
  );
});

test('plan rejects invalid plan with non-zero exit', () => {
  startSession(dir, { storyboardPath: join(dir, 'storyboard.json') });
  const planFile = join(dir, 'plan.json');
  writeFileSync(planFile, JSON.stringify({ ...validPlan, tier: 'junk' }));
  assert.notStrictEqual(runPlan({ artifactsDir: dir, planPath: planFile }), 0);
});

test('plan rejects when no session is active', () => {
  const planFile = join(dir, 'plan.json');
  writeFileSync(planFile, JSON.stringify(validPlan));
  assert.notStrictEqual(runPlan({ artifactsDir: dir, planPath: planFile }), 0);
});

test('plan rejects when called twice in same session', () => {
  startSession(dir, { storyboardPath: join(dir, 'storyboard.json') });
  const planFile = join(dir, 'plan.json');
  writeFileSync(planFile, JSON.stringify(validPlan));
  assert.strictEqual(runPlan({ artifactsDir: dir, planPath: planFile }), 0);
  assert.notStrictEqual(runPlan({ artifactsDir: dir, planPath: planFile }), 0);
});

// -------- chapter --------
test('chapter appends event in order, case-insensitive', () => {
  startSession(dir, { storyboardPath: join(dir, 'storyboard.json') });
  const planFile = join(dir, 'plan.json');
  writeFileSync(planFile, JSON.stringify(validPlan));
  runPlan({ artifactsDir: dir, planPath: planFile });
  assert.strictEqual(runChapter({ artifactsDir: dir, title: 'INTRO' }), 0);
  assert.strictEqual(events()[0].type, 'chapter');
  assert.strictEqual(events()[0].title, 'Intro');
});

test('chapter rejects titles not in the plan', () => {
  startSession(dir, { storyboardPath: join(dir, 'storyboard.json') });
  writeFileSync(join(dir, 'plan.json'), JSON.stringify(validPlan));
  runPlan({ artifactsDir: dir, planPath: join(dir, 'plan.json') });
  assert.notStrictEqual(runChapter({ artifactsDir: dir, title: 'Unknown' }), 0);
});

test('chapter rejects out-of-order advance', () => {
  startSession(dir, { storyboardPath: join(dir, 'storyboard.json') });
  writeFileSync(join(dir, 'plan.json'), JSON.stringify(validPlan));
  runPlan({ artifactsDir: dir, planPath: join(dir, 'plan.json') });
  runChapter({ artifactsDir: dir, title: 'Intro' });
  assert.notStrictEqual(runChapter({ artifactsDir: dir, title: 'Result' }), 0);
});

// -------- narrate/note --------
test('narrate appends a narrate event', () => {
  setupSession();
  assert.strictEqual(runNarrate({ artifactsDir: dir, text: 'hello' }), 0);
  assert.strictEqual(events()[0].type, 'narrate');
  assert.strictEqual(events()[0].text, 'hello');
});

test('narrate rejects when no session', () => {
  setupSession();
  clearSession(dir);
  assert.notStrictEqual(runNarrate({ artifactsDir: dir, text: 'x' }), 0);
});

test('note appends a note event', () => {
  setupSession();
  assert.strictEqual(runNote({ artifactsDir: dir, text: 'Setup' }), 0);
  assert.strictEqual(events()[0].type, 'note');
});

// -------- callout --------
test('callout decrements budget and writes event', () => {
  setupSession({ callout: 2 });
  const code = runCallout({ artifactsDir: dir, text: 'The filter', selector: '#filter', anchor: 'top' });
  assert.strictEqual(code, 0);
  assert.strictEqual(readSession(dir)!.calloutBudget, 1);
  assert.strictEqual(events()[0].type, 'callout');
  assert.strictEqual(events()[0].anchor, 'top');
});

test('callout rejects when budget zero', () => {
  setupSession({ callout: 0 });
  assert.notStrictEqual(runCallout({ artifactsDir: dir, text: 'x', selector: '#x' }), 0);
});

test('callout rejects invalid anchor', () => {
  setupSession({ callout: 1 });
  assert.notStrictEqual(
    runCallout({ artifactsDir: dir, text: 'x', selector: '#x', anchor: 'upside-down' as any }),
    0
  );
});

// -------- emphasize --------
test('emphasize flips pending and decrements', () => {
  setupSession({ emphasize: 2 });
  assert.strictEqual(runEmphasize({ artifactsDir: dir }), 0);
  const s = readSession(dir)!;
  assert.strictEqual(s.emphasizePending, true);
  assert.strictEqual(s.emphasizeBudget, 1);
});

test('emphasize rejects when already pending', () => {
  setupSession({ emphasize: 2 });
  runEmphasize({ artifactsDir: dir });
  assert.notStrictEqual(runEmphasize({ artifactsDir: dir }), 0);
});

test('emphasize rejects when budget zero', () => {
  setupSession({ emphasize: 0 });
  assert.notStrictEqual(runEmphasize({ artifactsDir: dir }), 0);
});

// -------- fastforward --------
test('fastforward start sets open state, stop clears it', () => {
  setupSession();
  assert.strictEqual(runFastforward({ artifactsDir: dir, action: 'start', factor: 4 }), 0);
  assert.notStrictEqual(readSession(dir)!.openFastforward, null);
  assert.strictEqual(runFastforward({ artifactsDir: dir, action: 'stop' }), 0);
  assert.strictEqual(readSession(dir)!.openFastforward, null);
});

test('fastforward rejects nesting', () => {
  setupSession();
  runFastforward({ artifactsDir: dir, action: 'start', factor: 4 });
  assert.notStrictEqual(runFastforward({ artifactsDir: dir, action: 'start' }), 0);
});

test('fastforward rejects stop without start', () => {
  setupSession();
  assert.notStrictEqual(runFastforward({ artifactsDir: dir, action: 'stop' }), 0);
});

test('fastforward default factor is 4', () => {
  setupSession();
  runFastforward({ artifactsDir: dir, action: 'start' });
  assert.strictEqual(events()[0].factor, 4);
});

test('fastforward rejects factor <= 1', () => {
  setupSession();
  assert.notStrictEqual(runFastforward({ artifactsDir: dir, action: 'start', factor: 1 }), 0);
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
  runEmphasize({ artifactsDir: dir });
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
