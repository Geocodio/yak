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
