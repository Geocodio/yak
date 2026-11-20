import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createHarness, start, SECRET } from './helpers/fake-sdk.mjs';

const finish = async (run) => { run.release(); run.send({ type: 'end' }); return run.exited; };

test('it requires start as the first line', async () => {
  const run = createHarness();
  run.send({ type: 'user', uuid: 'u1', text: 'hi' });
  assert.equal(await run.exited, 2);
  const [line] = run.lines();
  assert.equal(line.type, 'runner');
  assert.equal(line.event, 'error');
});

test('it requires a placeholder and a base url in start', async () => {
  const run = createHarness();
  run.send({ type: 'start', model: 'm', cwd: '/w', claudeConfigDir: '/c' });
  assert.equal(await run.exited, 2);
  assert.equal(run.lines()[0].event, 'error');
});

test('it emits runner started then done and exits 0 when the stream ends after end', async () => {
  const run = createHarness();
  run.send(start());
  await run.tick();
  assert.equal(await finish(run), 0);
  const events = run.lines();
  assert.equal(events[0].event, 'started');
  assert.equal(events.at(-1).event, 'done');
});

test('it forwards user lines with uuid and priority', async () => {
  const run = createHarness();
  run.send(start());
  run.send({ type: 'user', uuid: 'u-1', text: 'first' });
  run.send({ type: 'user', uuid: 'u-2', text: 'steer', priority: 'now' });
  await run.tick();
  assert.equal(run.calls.prompts.length, 2);
  assert.equal(run.calls.prompts[0].uuid, 'u-1');
  assert.equal(run.calls.prompts[0].message.content, 'first');
  assert.equal('priority' in run.calls.prompts[0], false);
  assert.equal(run.calls.prompts[1].priority, 'now');
  await finish(run);
});

test('it rejects a user line without uuid', async () => {
  const run = createHarness();
  run.send(start());
  run.send({ type: 'user', text: 'no uuid' });
  await run.tick();
  assert.equal(run.calls.prompts.length, 0);
  assert.equal(run.lines().some((line) => line.event === 'bad_line'), true);
  await finish(run);
});

test('it calls setModel on set_model', async () => {
  const run = createHarness();
  run.send(start());
  await run.tick();
  run.send({ type: 'set_model', model: 'claude-opus-4-7' });
  await run.tick();
  assert.deepEqual(run.calls.setModel, ['claude-opus-4-7']);
  await finish(run);
});

test('it applies the effort level on set_effort and resets it on null', async () => {
  const run = createHarness();
  run.send(start());
  await run.tick();
  run.send({ type: 'set_effort', effort: 'high' });
  run.send({ type: 'set_effort', effort: null });
  await run.tick();
  assert.deepEqual(run.calls.applyFlagSettings, [{ effortLevel: 'high' }, { effortLevel: null }]);
  await finish(run);
});

test('it calls interrupt and writes runner interrupted', async () => {
  const run = createHarness();
  run.send(start());
  await run.tick();
  run.send({ type: 'interrupt' });
  await run.tick();
  assert.equal(run.calls.interrupts, 1);
  assert.equal(run.lines().some((line) => line.event === 'interrupted'), true);
  await finish(run);
});

test('it emits exit_after_result when the SDK throws after a result', async () => {
  const run = createHarness({ messages: [{ type: 'result', subtype: 'success' }], throwAfter: 'exit 1' });
  run.send(start());
  assert.equal(await run.exited, 0);
  const events = run.lines();
  assert.equal(events[0].event, 'started');
  assert.equal(typeof events[0].pid, 'number');
  assert.deepEqual(events[1], { type: 'result', subtype: 'success' });
  assert.equal(events[2].event, 'exit_after_result');
  assert.equal(events[3].event, 'done');
});

test('it reports error and exits 1 when it throws before a result', async () => {
  const run = createHarness({ throwAfter: 'boom' });
  run.send(start());
  assert.equal(await run.exited, 1);
  assert.equal(run.lines().at(-1).event, 'error');
});

test('it reports only the length of a line that does not parse', async () => {
  const run = createHarness();
  const garbled = `{"type":"start","token":"${SECRET}"} oops`;
  run.input.write(garbled + '\n');
  await run.tick();
  const [line] = run.lines();
  assert.equal(line.event, 'bad_line');
  assert.equal(line.length, garbled.length);
  assert.equal('line' in line, false);
  assert.equal(run.written.join('').includes('ghp_'), false);
  run.input.end();
});

test('it passes SDK messages through unchanged', async () => {
  const messages = [
    { type: 'assistant', message: { role: 'assistant', content: [{ type: 'text', text: 'hello' }] }, session_id: 's' },
    { type: 'result', subtype: 'success', result: 'ok', session_id: 's' },
    { type: 'command_lifecycle', command_uuid: 'c-1', state: 'started', uuid: 'x', session_id: 's' },
  ];
  const run = createHarness({ messages });
  run.send(start());
  await run.tick();
  assert.deepEqual(run.lines().filter((line) => line.type !== 'runner'), messages);
  await finish(run);
});

test('it never writes GH_TOKEN to stdout', async () => {
  const delta = (index, text) => ({ type: 'stream_event', event: { type: 'content_block_delta', index, delta: { type: 'text_delta', text } } });
  const half = SECRET.length >> 1;
  const run = createHarness({ messages: [{ type: 'assistant', text: `token ${SECRET} here` }, delta(0, SECRET.slice(0, half)), delta(0, SECRET.slice(half))], throwAfter: `failed with ${SECRET}` });
  run.send(start());
  await run.exited;
  const output = run.written.join('');
  assert.equal(output.includes(SECRET), false);
  assert.match(output, /token \[redacted\] here/);
});

test('it does not hand the CLI a spawn hook', async () => {
  const run = createHarness();
  run.send(start());
  await run.tick();
  assert.equal('spawnClaudeCodeProcess' in run.calls.options, false);
  await finish(run);
});

test('it passes the yak server in options.mcpServers', async () => {
  const run = createHarness();
  run.send(start());
  await run.tick();
  assert.equal(run.calls.options.mcpServers.yak.name, 'yak');
  await finish(run);
});

test('it routes a tool_result command to the pending call', async () => {
  const run = createHarness();
  run.send(start());
  await run.tick();
  const listThreads = run.calls.options.mcpServers.yak.instance.tools.find((entry) => entry.name === 'list_threads');
  const pending = listThreads.handler({});
  await run.tick();
  const callLine = run.lines().find((line) => line.type === 'yak.tool_call');
  assert.equal(callLine.tool, 'list_threads');
  run.send({ type: 'tool_result', call_id: callLine.call_id, is_error: false, content: 'ok' });
  assert.deepEqual(await pending, { content: [{ type: 'text', text: 'ok' }] });
  await finish(run);
});

test('it does not rewrite the tool_call line except for secrets', async () => {
  const run = createHarness();
  run.send(start());
  await run.tick();
  const sendMessage = run.calls.options.mcpServers.yak.instance.tools.find((entry) => entry.name === 'send_message');
  sendMessage.handler({ to: 'a', text: `plain and ${SECRET}` });
  await run.tick();
  const callLine = run.lines().find((line) => line.type === 'yak.tool_call');
  assert.equal(callLine.input.text, 'plain and [redacted]');
  assert.equal(callLine.input.to, 'a');
  await finish(run);
});

test('it answers every pending call with an error when the runner ends', async () => {
  const run = createHarness();
  run.send(start());
  await run.tick();
  const listThreads = run.calls.options.mcpServers.yak.instance.tools.find((entry) => entry.name === 'list_threads');
  const pending = listThreads.handler({});
  run.send({ type: 'end' });
  const result = await pending;
  assert.equal(result.isError, true);
  run.release();
  await run.exited;
});

const toolUse = { type: 'assistant', message: { content: [{ type: 'tool_use', id: 't1', name: 'Bash', input: {} }] } };
const endTurn = { type: 'assistant', message: { stop_reason: 'end_turn', content: [{ type: 'text', text: 'done' }] } };

function sweepOptions() {
  const timers = [];
  const kills = [];
  const listed = [
    { pid: process.pid, ppid: 1, uid: 1000, startedAtMs: 0, command: 'node runner.mjs' },
    { pid: 900, ppid: 1, uid: 1000, startedAtMs: 10_000, command: 'node playwright run-server' },
  ];
  return {
    timers,
    kills,
    options: {
      agentUid: 1000,
      now: () => 5_000,
      listProcesses: () => listed,
      kill: (pid, signal) => kills.push([pid, signal]),
      setTimer: (callback, delay) => { timers.push({ callback, delay }); return timers.length; },
      clearTimer: (id) => { timers[id - 1].cleared = true; },
    },
  };
}

test('it sweeps leftover processes 60 seconds after the final answer without a result', async () => {
  const sweeping = sweepOptions();
  const run = createHarness({ messages: [toolUse, endTurn], runnerOptions: sweeping.options });
  run.send(start());
  await run.tick();
  assert.equal(sweeping.timers[0].delay, 60_000);
  sweeping.timers[0].callback();
  assert.deepEqual(sweeping.kills, [[900, 'SIGTERM']]);
  assert.equal(sweeping.timers[1].delay, 5_000);
  sweeping.timers[1].callback();
  assert.deepEqual(sweeping.kills.at(-1), [900, 'SIGKILL']);
  const swept = run.lines().find((line) => line.event === 'swept');
  assert.deepEqual(swept.pids, [900]);
  assert.deepEqual(swept.commands, ['node playwright run-server']);
  await finish(run);
});

const recordedTurn = () => readFileSync(new URL('../../tests/Fixtures/runner-streams/natural-turn-end.jsonl', import.meta.url), 'utf8').split('\n').filter(Boolean).map((line) => JSON.parse(line)).filter((line) => ['assistant', 'result'].includes(line.type)).map(({ _ms, ...line }) => line);

test('it arms the sweep on the recorded final answer when no result follows', async () => {
  const sweeping = sweepOptions();
  const messages = [toolUse, ...recordedTurn().filter((line) => line.type === 'assistant')];
  assert.equal(messages.at(-1).message.stop_reason, null);
  const run = createHarness({ messages, runnerOptions: sweeping.options });
  run.send(start());
  await run.tick();
  assert.equal(sweeping.timers[0].delay, 60_000);
  await finish(run);
});

test('it never sweeps the full recorded turn that ends with a result', async () => {
  const sweeping = sweepOptions();
  const run = createHarness({ messages: [toolUse, ...recordedTurn()], runnerOptions: sweeping.options });
  run.send(start());
  await run.tick();
  assert.equal(sweeping.timers[0].cleared, true);
  assert.deepEqual(sweeping.kills, []);
  await finish(run);
});

test('it cancels the sweep when a result arrives first', async () => {
  const sweeping = sweepOptions();
  const run = createHarness({ messages: [toolUse, endTurn, { type: 'result', subtype: 'success' }], runnerOptions: sweeping.options });
  run.send(start());
  await run.tick();
  assert.equal(sweeping.timers[0].cleared, true);
  assert.deepEqual(sweeping.kills, []);
  await finish(run);
});

test('it does not arm the sweep for a subagent end_turn', async () => {
  const sweeping = sweepOptions();
  const run = createHarness({ messages: [toolUse, { ...endTurn, parent_tool_use_id: 'toolu_sub' }], runnerOptions: sweeping.options });
  run.send(start());
  await run.tick();
  assert.equal(sweeping.timers.length, 0);
  await finish(run);
});

test('it cancels an armed sweep when the main agent keeps working', async () => {
  const sweeping = sweepOptions();
  const run = createHarness({ messages: [toolUse, endTurn, toolUse], runnerOptions: sweeping.options });
  run.send(start());
  await run.tick();
  assert.equal(sweeping.timers[0].cleared, true);
  assert.equal(sweeping.timers.length, 1);
  await finish(run);
});

test('it cancels an armed sweep on a new user line and restarts the turn clock', async () => {
  const sweeping = sweepOptions();
  const run = createHarness({ messages: [toolUse, endTurn], runnerOptions: sweeping.options });
  run.send(start());
  await run.tick();
  run.send({ type: 'user', uuid: 'u2', text: 'more' });
  await run.tick();
  assert.equal(sweeping.timers[0].cleared, true);
  sweeping.timers[0].callback();
  assert.deepEqual(sweeping.kills, []);
  await finish(run);
});

test('it skips SIGKILL for a pid whose start time changed', async () => {
  const sweeping = sweepOptions();
  const run = createHarness({ messages: [toolUse, endTurn], runnerOptions: { ...sweeping.options, listProcesses: (() => { let calls = 0; return () => { calls += 1; return [{ pid: process.pid, ppid: 1, uid: 1000, startedAtMs: 0, command: 'r' }, { pid: 900, ppid: 1, uid: 1000, startedAtMs: calls === 1 ? 10_000 : 99_000, command: 'x' }]; }; })() } });
  run.send(start());
  await run.tick();
  sweeping.timers[0].callback();
  sweeping.timers[1].callback();
  assert.deepEqual(sweeping.kills, [[900, 'SIGTERM']]);
  await finish(run);
});

const backgroundTasks = (count) => ({ type: 'system', subtype: 'background_tasks_changed', tasks: Array.from({ length: count }, (_, index) => ({ task_id: `a${index}` })) });

test('it does not sweep while background tasks run and sweeps again once they are over', async () => {
  const sweeping = sweepOptions();
  const run = createHarness({ messages: [toolUse, backgroundTasks(1), endTurn], runnerOptions: sweeping.options });
  run.send(start());
  await run.tick();
  sweeping.timers[0].callback();
  assert.deepEqual(sweeping.kills, []);
  assert.equal(run.lines().some((line) => line.event === 'swept'), false);
  await finish(run);

  const later = sweepOptions();
  const second = createHarness({ messages: [toolUse, backgroundTasks(1), backgroundTasks(0), endTurn], runnerOptions: later.options });
  second.send(start());
  await second.tick();
  later.timers[0].callback();
  assert.deepEqual(later.kills, [[900, 'SIGTERM']]);
  await finish(second);
});

test('it cancels the sweep when the session reports idle', async () => {
  const sweeping = sweepOptions();
  const idle = { type: 'system', subtype: 'session_state_changed', state: 'idle' };
  const run = createHarness({ messages: [toolUse, endTurn, idle], runnerOptions: sweeping.options });
  run.send(start());
  await run.tick();
  assert.equal(sweeping.timers[0].cleared, true);
  await finish(run);
});

test('it does not arm the sweep for an agent line that has no parent tool use', async () => {
  const sweeping = sweepOptions();
  const run = createHarness({ messages: [toolUse, { ...endTurn, agent_id: 'a1' }], runnerOptions: sweeping.options });
  run.send(start());
  await run.tick();
  assert.equal(sweeping.timers.length, 0);
  await finish(run);
});

test('it stops one agent with stopTask and reports it', async () => {
  const run = createHarness();
  run.send(start());
  await run.tick();
  run.send({ type: 'stop_agent', taskId: 'a1b2' });
  await run.tick();
  assert.deepEqual(run.calls.stopTask, ['a1b2']);
  assert.deepEqual(run.lines().find((line) => line.event === 'agent_stopped'), { type: 'runner', event: 'agent_stopped', taskId: 'a1b2' });
  await finish(run);
});

test('it reports a stop that fails, an unknown id and a missing id', async () => {
  const run = createHarness({ stopTask: async (taskId) => { if (taskId === 'gone') throw new Error('No such task'); } });
  run.send(start());
  await run.tick();
  run.send({ type: 'stop_agent', taskId: 'gone' });
  run.send({ type: 'stop_agent' });
  await run.tick();
  const failures = run.lines().filter((line) => line.event === 'agent_stop_failed');
  assert.equal(failures.length, 2);
  assert.match(failures.find((line) => line.taskId === 'gone').error, /No such task/);
  assert.equal(failures.some((line) => line.taskId === null), true);
  await finish(run);
});

test('it reports a stop that never answers as failed after the timeout', async () => {
  const timers = [];
  const run = createHarness({ stopTask: () => new Promise(() => {}), runnerOptions: { setTimer: (callback, delay) => { timers.push({ callback, delay }); return timers.length; }, clearTimer: () => {} } });
  run.send(start());
  await run.tick();
  run.send({ type: 'stop_agent', taskId: 'slow' });
  await run.tick();
  const stopTimer = timers.find((timer) => timer.delay === 15_000);
  stopTimer.callback();
  await run.tick();
  assert.match(run.lines().find((line) => line.event === 'agent_stop_failed').error, /timed out/);
  await finish(run);
});
