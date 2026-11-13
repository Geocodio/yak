import assert from 'node:assert/strict';
import { test } from 'node:test';
import { countSteps, describeCounts, formatSeconds, formatStepDuration, groupActivity } from './groupActivity.ts';

let nextId = 1;
const row = (category, extra = {}) => ({
    id: nextId++,
    badge: null,
    text: extra.label ?? category,
    at: '12:00:00 PM',
    createdAt: '2026-11-11T12:00:00Z',
    kind: category === 'milestone' ? 'level' : category === 'narrative' || category === 'thinking' ? 'assistant' : 'tool',
    error: false,
    milestone: category === 'milestone',
    tool: category === 'command' ? 'Bash' : category === 'read' ? 'Read' : null,
    category,
    label: category,
    durationMs: null,
    exitCode: null,
    running: false,
    retryOf: null,
    ...extra,
});

test('consecutive steps share a group and a sentence or milestone splits them', () => {
    const nodes = groupActivity([row('narrative'), row('read'), row('command'), row('thinking'), row('narrative'), row('command'), row('milestone')]);

    assert.deepEqual(
        nodes.map((node) => [node.type, node.type === 'group' ? node.steps.length : 1]),
        [['narrative', 1], ['group', 3], ['narrative', 1], ['group', 1], ['milestone', 1]],
    );
});

test('prompt rows stay out of the stream', () => {
    const nodes = groupActivity([row('milestone', { kind: 'prompt' }), row('command')]);

    assert.deepEqual(nodes.map((node) => node.type), ['group']);
});

test('a passing command after an identical failing one is a retry pair', () => {
    const nodes = groupActivity([
        row('command', { label: 'composer install', error: true, exitCode: 2 }),
        row('narrative'),
        row('command', { label: 'composer install', exitCode: 0 }),
        row('command', { label: 'php artisan migrate', exitCode: 0 }),
    ]);
    const steps = nodes.filter((node) => node.type === 'group').flatMap((node) => node.steps);

    assert.deepEqual(steps.map((step) => [step.retried, step.retryPassed]), [[true, false], [false, true], [false, false]]);
});

test('a failure that never passes again is not marked retried', () => {
    const [group] = groupActivity([row('command', { label: 'npm ci', error: true }), row('command', { label: 'npm ci', error: true })]);

    assert.deepEqual(group.steps.map((step) => step.retried), [false, false]);
});

test('counts and phrasing cover every category in a fixed order', () => {
    const counts = countSteps([row('command'), row('command'), row('read'), row('thinking'), row('narrative')]);

    assert.equal(describeCounts(counts), 'Ran 2 commands, read 1 file, 1 thinking step');
});

test('durations read the way the stream writes them', () => {
    assert.equal(formatSeconds(38), '38s');
    assert.equal(formatSeconds(749), '12m 29s');
    assert.equal(formatSeconds(3900), '1h 05m');
    assert.equal(formatStepDuration(400), '0.4s');
    assert.equal(formatStepDuration(null), null);
});
