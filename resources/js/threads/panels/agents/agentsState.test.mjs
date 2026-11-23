import assert from 'node:assert/strict';
import { test } from 'node:test';
import { agentsTabCount, buildTree, cardSubtitle, continuedByRun, countRunning, flattenTree, formatAgentDuration, formatTokens, matchChild, mergeAgents, nowLabel, rowSubtitle, runningSummary, workflowPhases } from './agentsState.ts';

const agent = (id, extra = {}) => ({
    id, kind: 'agent', parentTaskId: null, toolUseId: null, depth: 1, agentType: 'general-purpose', description: id, workflowName: null, model: null, isBackground: true, status: 'running',
    lastTool: null, lastToolTarget: null, prompt: null, summary: null, totalTokens: 0, toolUses: 0, durationMs: 0, startedAt: null, finishedAt: null, stopRequestedAt: null, stopError: null, workflowProgress: null,
    startedByTurnId: null, followUpTurnId: null, messages: [], ...extra,
});

test('the running summary pluralizes agents and workflows', () => {
    assert.equal(runningSummary({ agents: 2, workflows: 1 }), '2 agents and 1 workflow running');
    assert.equal(runningSummary({ agents: 1, workflows: 0 }), '1 agent running');
    assert.equal(runningSummary({ agents: 0, workflows: 3 }), '3 workflows running');
    assert.equal(runningSummary({ agents: 1, workflows: 2 }), '1 agent and 2 workflows running');
    assert.equal(runningSummary({ agents: 0, workflows: 0 }), null);
});

test('only running rows are counted, per kind', () => {
    const rows = [agent('a'), agent('b', { status: 'completed' }), agent('w', { kind: 'workflow' }), agent('c', { status: 'stopped' })];

    assert.deepEqual(countRunning(rows), { agents: 1, workflows: 1 });
});

test('the tree nests by parent and puts running agents first', () => {
    const rows = [agent('done', { status: 'completed' }), agent('parent'), agent('child', { parentTaskId: 'parent' }), agent('orphan', { parentTaskId: 'gone', status: 'failed' })];
    const flat = flattenTree(buildTree(rows));

    assert.deepEqual(flat.map((row) => [row.agent.id, row.depth, row.parent?.id ?? null]), [['parent', 0, null], ['child', 1, 'parent'], ['done', 0, null], ['orphan', 0, null]]);
});

test('live rows win over loaded rows but keep the loaded messages', () => {
    const message = { id: 1, text: 'hi', authorName: null, sentAt: 'x', forwardedAt: null };
    const loaded = [agent('a', { messages: [message] })];
    const { messages: _ignored, ...liveA } = agent('a', { status: 'completed', totalTokens: 5 });
    const { messages: _none, ...liveB } = agent('b', { startedAt: '2026-11-20T10:00:00Z' });
    const merged = mergeAgents(loaded, { a: liveA, b: liveB });

    assert.deepEqual(merged.map((row) => row.id), ['a', 'b']);
    assert.equal(merged[0].status, 'completed');
    assert.deepEqual(merged[0].messages, [message]);
    assert.deepEqual(merged[1].messages, []);
});

test('durations and tokens read like the mockup', () => {
    assert.equal(formatAgentDuration(372000), '6m 12s');
    assert.equal(formatAgentDuration(62000), '1m 02s');
    assert.equal(formatAgentDuration(28000), '28s');
    assert.equal(formatAgentDuration(3900000), '1h 05m');
    assert.equal(formatTokens(41210), '41k tokens');
    assert.equal(formatTokens(1500), '1.5k tokens');
    assert.equal(formatTokens(900), '900 tokens');
});

test('the card subtitle shows the live tool while collapsed and the totals otherwise', () => {
    const now = Date.parse('2026-11-20T10:06:12Z');
    const running = agent('a', { startedAt: '2026-11-20T10:00:00Z', totalTokens: 41210, toolUses: 23, lastTool: 'Write' });

    assert.equal(cardSubtitle(running, now, false), 'general-purpose · background · Running 6m 12s · now: Write');
    assert.equal(cardSubtitle(running, now, true), 'general-purpose · background · Running 6m 12s · 41k tokens · 23 tool uses');
    assert.equal(cardSubtitle(agent('a', { status: 'completed', durationMs: 544000, isBackground: false }), now, false), 'general-purpose · foreground · Finished after 9m 04s');
});

test('the row subtitle names who started the agent', () => {
    const parent = agent('p', { description: 'Build mockups' });
    const now = Date.now();

    assert.equal(rowSubtitle(agent('c', { description: 'Check tokens', durationMs: 62000, startedAt: new Date(now - 62000).toISOString() }), parent, now), 'Agent · started by Build mockups · 1m 02s');
    assert.equal(rowSubtitle(agent('s', { status: 'stopped', durationMs: 260000 }), null, now), 'Agent · stopped · 4m 20s · started by Claude');
});

test('workflow phases tile done, running and waiting', () => {
    const progress = [
        { type: 'workflow_phase', index: 1, title: 'Capture' },
        { type: 'workflow_phase', index: 2, title: 'Review' },
        { type: 'workflow_phase', index: 3, title: 'Report' },
        { type: 'workflow_agent', phaseIndex: 1, state: 'done' },
        { type: 'workflow_agent', phaseIndex: 2, state: 'done' },
        { type: 'workflow_agent', phaseIndex: 2, state: 'done' },
        { type: 'workflow_agent', phaseIndex: 2, state: 'start' },
    ];

    assert.deepEqual(workflowPhases(progress), [
        { title: 'Capture', state: 'done', label: 'done' },
        { title: 'Review', state: 'running', label: '2 of 3 agents' },
        { title: 'Report', state: 'waiting', label: 'waiting' },
    ]);
    assert.deepEqual(workflowPhases(null), []);
});

test('the tab count is what runs, or everything once nothing does', () => {
    assert.equal(agentsTabCount([agent('a'), agent('b', { status: 'completed' })], 1), 1);
    assert.equal(agentsTabCount([agent('b', { status: 'completed' })], 2), 3);
    assert.equal(agentsTabCount([], 0), 0);
});

test('agents that woke Claude are grouped by the run they started', () => {
    const byRun = continuedByRun([agent('a', { followUpTurnId: 7 }), agent('b', { followUpTurnId: 7 }), agent('c')]);

    assert.deepEqual([...byRun.keys()], [7]);
    assert.equal(byRun.get(7).length, 2);
});

test('the now label shows a file by its name and other targets as they are', () => {
    assert.equal(nowLabel(agent('a', { lastTool: 'Write', lastToolTarget: '/workspace/mockups/crm-pipeline.html' })), 'Write crm-pipeline.html');
    assert.equal(nowLabel(agent('a', { lastTool: 'Bash', lastToolTarget: 'ls /a/b' })), 'Bash ls /a/b');
    assert.equal(nowLabel(agent('a', { lastTool: 'Read' })), 'Read');
    assert.equal(nowLabel(agent('a')), null);
    assert.equal(cardSubtitle(agent('a', { startedAt: '2026-11-20T10:00:00Z', lastTool: 'Write', lastToolTarget: '/w/crm-pipeline.html' }), Date.parse('2026-11-20T10:00:28Z'), false), 'general-purpose · background · Running 28s · now: Write crm-pipeline.html');
});

test('a log step matches the agent it started by tool call id, even when descriptions are equal', () => {
    const first = agent('one', { description: 'Same', toolUseId: 'toolu_1' });
    const second = agent('two', { description: 'Same', toolUseId: 'toolu_2' });
    const used = new Set();

    assert.equal(matchChild({ subject: 'Same', agentToolUseIds: ['toolu_2'] }, [first, second], used)?.id, 'two');
    assert.equal(matchChild({ subject: 'Same', agentToolUseIds: ['toolu_9'] }, [first, second], used), undefined);
    assert.equal(matchChild({ subject: 'Same' }, [first, second], new Set(['one']))?.id, 'two');
    assert.equal(matchChild({ subject: 'Other', agentToolUseIds: [] }, [first, second], used)?.id, 'one');
});

test('the sub-thread signature changes when a sub-thread appears or changes state', async () => {
    const { childrenSignature } = await import('./agentsState.ts');
    const none = childrenSignature({});
    const queued = childrenSignature({ c1: { sequence: 1, data: { state: 'queued' } } });
    const working = childrenSignature({ c1: { sequence: 2, data: { state: 'working' } } });

    assert.notEqual(none, queued);
    assert.notEqual(queued, working);
    assert.equal(working, childrenSignature({ c1: { sequence: 9, data: { state: 'working' } } }));
});

test('overlayChildren lays the live card state over loaded rows and leaves others alone', async () => {
    const { overlayChildren } = await import('./agentsState.ts');
    const rows = [{ id: 'a', state: 'queued', step: null, title: 'A' }, { id: 'b', state: 'done', step: null, title: 'B' }];

    assert.equal(overlayChildren(rows, {}), rows);

    const result = overlayChildren(rows, { a: { sequence: 3, data: { state: 'working', step: 'Reading' } } });

    assert.deepEqual(result[0], { id: 'a', state: 'working', step: 'Reading', title: 'A' });
    assert.equal(result[1], rows[1]);
});
