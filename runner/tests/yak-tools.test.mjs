import { test, mock } from 'node:test';
import assert from 'node:assert/strict';
import { createYakTools, TOOL_CALL_TIMEOUT_MS } from '../lib/yak-tools.mjs';
import { fakeTool, fakeCreateSdkMcpServer } from './helpers/fake-sdk.mjs';

function setup(options = {}) {
  const written = [];
  let counter = 0;
  const yak = createYakTools({
    write: (object) => written.push(object),
    randomUuid: () => `call-${++counter}`,
    tool: fakeTool,
    createSdkMcpServer: fakeCreateSdkMcpServer,
    ...options,
  });
  const byName = (name) => yak.server.instance.tools.find((entry) => entry.name === name);
  return { yak, written, byName };
}

test('it writes a yak.tool_call line with a fresh call_id and resolves on tool_result', async () => {
  const { yak, written, byName } = setup();
  const pending = byName('list_threads').handler({});
  assert.deepEqual(written, [{ type: 'yak.tool_call', call_id: 'call-1', tool: 'list_threads', input: {} }]);
  yak.resolveToolResult({ type: 'tool_result', call_id: 'call-1', is_error: false, content: 'two threads' });
  assert.deepEqual(await pending, { content: [{ type: 'text', text: 'two threads' }] });
});

test('it returns an error result when is_error is true', async () => {
  const { yak, byName } = setup();
  const pending = byName('send_message').handler({ to: 'a', text: 'b' });
  yak.resolveToolResult({ call_id: 'call-1', is_error: true, content: 'no such thread' });
  assert.deepEqual(await pending, { content: [{ type: 'text', text: 'no such thread' }], isError: true });
});

test('it times out after timeoutMs with an error result and forgets the call', async (context) => {
  context.mock.timers.enable({ apis: ['setTimeout'] });
  const { yak, byName } = setup({ timeoutMs: 50 });
  const pending = byName('list_threads').handler({});
  context.mock.timers.tick(50);
  const result = await pending;
  assert.equal(result.isError, true);
  assert.match(result.content[0].text, /timed out/);
  yak.resolveToolResult({ call_id: 'call-1', is_error: false, content: 'late' });
  assert.equal(TOOL_CALL_TIMEOUT_MS, 120000);
});

test('it ignores a tool_result for an unknown or already settled call_id', async () => {
  const { yak, byName } = setup();
  yak.resolveToolResult({ call_id: 'nope', is_error: false, content: 'x' });
  const pending = byName('list_threads').handler({});
  yak.resolveToolResult({ call_id: 'call-1', is_error: false, content: 'first' });
  yak.resolveToolResult({ call_id: 'call-1', is_error: true, content: 'second' });
  assert.equal((await pending).content[0].text, 'first');
});

test('it uses a unique call_id per call', () => {
  const { written, byName } = setup();
  byName('list_threads').handler({});
  byName('list_threads').handler({});
  assert.deepEqual(written.map((line) => line.call_id), ['call-1', 'call-2']);
});

test('it answers every pending call with an error on rejectAll', async () => {
  const { yak, byName } = setup();
  const pending = byName('list_threads').handler({});
  yak.rejectAll('runner ended');
  assert.deepEqual(await pending, { content: [{ type: 'text', text: 'runner ended' }], isError: true });
});

test('it defines exactly the six Yak tools on a server named yak', () => {
  const { yak } = setup();
  assert.equal(yak.server.name, 'yak');
  assert.deepEqual(yak.server.instance.tools.map((entry) => entry.name).sort(),
    ['add_repository', 'list_threads', 'request_refresh', 'send_message', 'set_merge_order', 'spawn_thread']);
});

test('it validates inputs with zod', () => {
  const { byName } = setup();
  const spawn = byName('spawn_thread').schema;
  const parsed = spawn.parse({ name: 'n', brief: 'b' });
  assert.equal(parsed.kind, 'general');
  assert.throws(() => spawn.parse({ name: 'n', brief: 'b', kind: 'bogus' }));
  assert.throws(() => spawn.parse({ name: 'n' }));
  assert.equal(spawn.parse({ name: 'n', brief: 'b', model: 'm', repo: 'r' }).repo, 'r');
  assert.throws(() => byName('send_message').schema.parse({ to: 'a' }));
  assert.throws(() => byName('add_repository').schema.parse({}));
  assert.deepEqual(byName('list_threads').schema.parse({}), {});
  assert.deepEqual(byName('request_refresh').schema.parse({}), {});
});

test('it forwards the validated input unchanged in the tool_call line', () => {
  const { written, byName } = setup();
  const input = { name: 'n', brief: 'b', kind: 'mockup', model: 'm' };
  byName('spawn_thread').handler(input);
  assert.deepEqual(written[0].input, input);
  assert.equal(written[0].tool, 'spawn_thread');
});

test('set_merge_order forwards the repository list in the tool_call line', () => {
  const { written, byName } = setup();
  byName('set_merge_order').handler({ repos: ['acme/lib', 'acme/app'] });
  assert.deepEqual(written[0], { type: 'yak.tool_call', call_id: 'call-1', tool: 'set_merge_order', input: { repos: ['acme/lib', 'acme/app'] } });
});
