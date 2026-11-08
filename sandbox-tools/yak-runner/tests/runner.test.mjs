import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { PassThrough } from 'node:stream';
import { buildChildEnvironment, createRunner } from '../lib/runner.mjs';

const SECRET = 'ghp_secret_passthrough_value';
const PLACEHOLDER = 'yakph-0123456789abcdef';
const BASE_URL = 'http://10.0.0.1:8787';

function harness({ messages = [], throwAfter = null, environment = { GH_TOKEN: SECRET } } = {}) {
  const input = new PassThrough();
  const written = [];
  const output = { write: (chunk) => written.push(chunk) };
  const calls = { options: null, prompts: [], setModel: [], interrupts: 0 };
  let releaseStream;
  const gate = new Promise((resolve) => { releaseStream = resolve; });

  const query = ({ prompt, options }) => {
    calls.options = options;
    const generator = (async function* () {
      (async () => { for await (const line of prompt) calls.prompts.push(line); })();
      for (const message of messages) yield message;
      if (throwAfter) throw new Error(throwAfter);
      await gate;
    })();
    generator.setModel = async (model) => { calls.setModel.push(model); };
    generator.interrupt = async () => { calls.interrupts += 1; };
    return generator;
  };

  const exited = new Promise((resolve) => {
    createRunner({ query, input, output, exit: resolve, environment });
  });
  const send = (object) => input.write(JSON.stringify(object) + '\n');
  const lines = () => written.join('').split('\n').filter(Boolean).map((line) => JSON.parse(line));
  const tick = () => new Promise((resolve) => setTimeout(resolve, 20));

  return { send, lines, exited, calls, tick, release: releaseStream, input, written };
}

const start = (extra = {}) => ({
  type: 'start',
  model: 'claude-haiku-4-5',
  cwd: '/workspace',
  claudeConfigDir: '/home/yak/.claude-thread',
  placeholder: PLACEHOLDER,
  anthropicBaseUrl: BASE_URL,
  ...extra,
});

test('it requires start as the first line', async () => {
  const run = harness();
  run.send({ type: 'user', uuid: 'u1', text: 'hi' });
  assert.equal(await run.exited, 2);
  const [line] = run.lines();
  assert.equal(line.type, 'runner');
  assert.equal(line.event, 'error');
});

test('it builds the child environment from scratch', () => {
  const environment = buildChildEnvironment(start(), {
    PATH: '/usr/bin', LANG: 'C.UTF-8', GH_TOKEN: 'gh', ANTHROPIC_API_KEY: 'yak-key',
    ANTHROPIC_AUTH_TOKEN: 'nope', SECRET_THING: 'x', HOME: '/root',
  });
  assert.equal(environment.ANTHROPIC_API_KEY, undefined);
  assert.equal(environment.ANTHROPIC_AUTH_TOKEN, undefined);
  assert.equal(environment.SECRET_THING, undefined);
  assert.equal(environment.PATH, '/usr/bin');
  assert.equal(environment.GH_TOKEN, 'gh');
  assert.equal(environment.HOME, '/home/yak');
  assert.equal(environment.CLAUDE_CONFIG_DIR, '/home/yak/.claude-thread');
  assert.equal(environment.CLAUDE_STREAM_IDLE_TIMEOUT_MS, '1800000');
  assert.equal(environment.CLAUDE_BYTE_STREAM_IDLE_TIMEOUT_MS, '1800000');
  assert.equal(environment.CLAUDE_CODE_OAUTH_TOKEN, PLACEHOLDER);
  assert.equal(environment.ANTHROPIC_BASE_URL, BASE_URL);
  assert.equal(environment.CLAUDE_CODE_DISABLE_NONESSENTIAL_TRAFFIC, '1');
  assert.equal(Object.keys(environment).some((name) => name.endsWith('_FILE_DESCRIPTOR')), false);
});

test('it authenticates every account kind with the placeholder and never with a key', () => {
  const environment = buildChildEnvironment(start(), { ANTHROPIC_API_KEY: 'inherited', CLAUDE_CODE_OAUTH_TOKEN: 'inherited-oauth', CLAUDE_CODE_API_KEY_FILE_DESCRIPTOR: '3' });
  assert.equal(environment.ANTHROPIC_API_KEY, undefined);
  assert.equal(environment.CLAUDE_CODE_OAUTH_TOKEN, PLACEHOLDER);
  assert.equal(environment.CLAUDE_CODE_API_KEY_FILE_DESCRIPTOR, undefined);
});

test('it requires a placeholder and a base url in start', async () => {
  const run = harness();
  run.send({ type: 'start', model: 'm', cwd: '/w', claudeConfigDir: '/c' });
  assert.equal(await run.exited, 2);
  assert.equal(run.lines()[0].event, 'error');
});

test('it never passes budget or turn limits to the SDK', async () => {
  const run = harness();
  run.send(start({ maxTurns: 3, maxBudgetUsd: 1, resume: 'sess-1', mcpServers: { a: {} }, additionalDirectories: ['/x'], settingSources: ['project'], appendSystemPrompt: 'extra' }));
  await run.tick();
  const { options } = run.calls;
  assert.equal('maxTurns' in options, false);
  assert.equal('maxBudgetUsd' in options, false);
  assert.equal(options.permissionMode, 'bypassPermissions');
  assert.equal(options.allowDangerouslySkipPermissions, true);
  assert.equal(options.resume, 'sess-1');
  assert.deepEqual(options.systemPrompt, { type: 'preset', preset: 'claude_code', append: 'extra' });
  assert.deepEqual(options.settingSources, ['project']);
  assert.deepEqual(options.additionalDirectories, ['/x']);
  run.release();
  run.send({ type: 'end' });
  await run.exited;
});

test('it reads mcp servers from the file named in start', async () => {
  const path = join(mkdtempSync(join(tmpdir(), 'mcp-')), 'mcp-config.json');
  writeFileSync(path, JSON.stringify({ mcpServers: { linear: { command: 'x' } } }));
  const run = harness();
  run.send(start({ mcpConfigPath: path }));
  await run.tick();
  assert.deepEqual(run.calls.options.mcpServers, { linear: { command: 'x' } });
  run.release();
  run.send({ type: 'end' });
  await run.exited;
});

test('it forwards user lines with their uuid and priority', async () => {
  const run = harness();
  run.send(start());
  run.send({ type: 'user', uuid: 'u-1', text: 'first' });
  run.send({ type: 'user', uuid: 'u-2', text: 'steer', priority: 'now' });
  await run.tick();
  assert.equal(run.calls.prompts.length, 2);
  assert.equal(run.calls.prompts[0].uuid, 'u-1');
  assert.equal(run.calls.prompts[0].message.content, 'first');
  assert.equal('priority' in run.calls.prompts[0], false);
  assert.equal(run.calls.prompts[1].uuid, 'u-2');
  assert.equal(run.calls.prompts[1].priority, 'now');
  run.release();
  run.send({ type: 'end' });
  await run.exited;
});

test('it calls setModel on set_model', async () => {
  const run = harness();
  run.send(start());
  await run.tick();
  run.send({ type: 'set_model', model: 'claude-opus-4-7' });
  await run.tick();
  assert.deepEqual(run.calls.setModel, ['claude-opus-4-7']);
  run.release();
  run.send({ type: 'end' });
  await run.exited;
});

test('it emits exit_after_result when the SDK throws after a result', async () => {
  const run = harness({ messages: [{ type: 'result', subtype: 'success' }], throwAfter: 'exit 1' });
  run.send(start());
  assert.equal(await run.exited, 0);
  const events = run.lines();
  assert.deepEqual(events[0].event, 'started');
  assert.equal(typeof events[0].pid, 'number');
  assert.deepEqual(events[1], { type: 'result', subtype: 'success' });
  assert.equal(events[2].event, 'exit_after_result');
  assert.equal(events[3].event, 'done');
});

test('it reports an error and exits 1 when the SDK throws before a result', async () => {
  const run = harness({ throwAfter: 'boom' });
  run.send(start());
  assert.equal(await run.exited, 1);
  assert.equal(run.lines().at(-1).event, 'error');
});

test('it never writes the passthrough token to stdout', async () => {
  const run = harness({ messages: [{ type: 'assistant', text: 'ok' }], throwAfter: `failed with ${SECRET}` });
  run.send(start());
  await run.exited;
  assert.equal(run.written.join('').includes(SECRET), false);
});

test('it reports only the length of a line that does not parse', async () => {
  const run = harness();
  const garbled = `{"type":"start","token":"${SECRET}"} oops`;
  run.input.write(garbled + '\n');
  await run.tick();
  const [line] = run.lines();
  assert.equal(line.event, 'bad_line');
  assert.equal(line.length, garbled.length);
  assert.equal(JSON.stringify(line).includes('ghp_'), false);
  assert.equal('line' in line, false);
  run.input.end();
});

test('it does not hand the CLI a spawn hook', async () => {
  const run = harness();
  run.send(start());
  await run.tick();
  assert.equal('spawnClaudeCodeProcess' in run.calls.options, false);
  run.release();
  run.send({ type: 'end' });
  await run.exited;
});

test('it redacts GH_TOKEN wherever it appears in output', async () => {
  const run = harness({ environment: { GH_TOKEN: 'ghp_passthrough_secret' }, messages: [{ type: 'assistant', text: 'token ghp_passthrough_secret here' }] });
  run.send(start());
  await run.tick();
  assert.equal(run.written.join('').includes('ghp_passthrough_secret'), false);
  assert.match(run.written.join(''), /token \[redacted\] here/);
  run.release();
  run.send({ type: 'end' });
  await run.exited;
});

test('it redacts a secret split across partial deltas without delaying other text', async () => {
  const delta = (index, text) => ({ type: 'stream_event', event: { type: 'content_block_delta', index, delta: { type: 'text_delta', text } } });
  const stop = (index) => ({ type: 'stream_event', event: { type: 'content_block_stop', index } });
  const half = SECRET.length >> 1;
  const run = harness({ messages: [delta(0, 'key is '), delta(0, SECRET.slice(0, half)), delta(0, SECRET.slice(half) + ' done'), delta(1, SECRET.slice(0, 4)), stop(1)] });
  run.send(start());
  await run.tick();
  const text = run.lines().filter((line) => line.type === 'stream_event').map((line) => line.event.delta?.text ?? '').join('|');
  assert.equal(text.includes(SECRET), false);
  assert.equal(run.written.join('').includes(SECRET.slice(0, half)), false);
  assert.match(text, /^key is \|\|\[redacted\] done\|/);
  assert.match(text, new RegExp(SECRET.slice(0, 4) + '\\|$'));
  run.release();
  run.send({ type: 'end' });
  await run.exited;
});
