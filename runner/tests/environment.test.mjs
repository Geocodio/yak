import { test } from 'node:test';
import assert from 'node:assert/strict';
import { buildChildEnvironment, sensitiveValues } from '../lib/environment.mjs';
import { start, PLACEHOLDER, BASE_URL } from './helpers/fake-sdk.mjs';

test('it builds the child environment from scratch', () => {
  const environment = buildChildEnvironment(start(), {
    PATH: '/usr/bin', LANG: 'C.UTF-8', GH_TOKEN: 'gh', ANTHROPIC_API_KEY: 'yak-key',
    ANTHROPIC_AUTH_TOKEN: 'nope', CLAUDE_CODE_OAUTH_TOKEN: 'inherited', AWS_SECRET: 'x', HOME: '/root',
  });
  assert.equal(environment.ANTHROPIC_API_KEY, undefined);
  assert.equal(environment.ANTHROPIC_AUTH_TOKEN, undefined);
  assert.equal(environment.AWS_SECRET, undefined);
  assert.equal(environment.PATH, '/usr/bin');
  assert.equal(environment.GH_TOKEN, 'gh');
  assert.equal(environment.HOME, '/home/yak');
  assert.equal(environment.CLAUDE_CONFIG_DIR, '/home/yak/.claude-thread');
  assert.equal(environment.CLAUDE_STREAM_IDLE_TIMEOUT_MS, '1800000');
  assert.equal(environment.CLAUDE_BYTE_STREAM_IDLE_TIMEOUT_MS, '1800000');
  assert.equal(environment.CLAUDE_CODE_OAUTH_TOKEN, PLACEHOLDER);
  assert.equal(environment.ANTHROPIC_BASE_URL, BASE_URL);
  assert.equal(environment.CLAUDE_CODE_DISABLE_NONESSENTIAL_TRAFFIC, '1');
});

test('it authenticates with the placeholder and never with a key', () => {
  const environment = buildChildEnvironment(start(), { ANTHROPIC_API_KEY: 'inherited', CLAUDE_CODE_OAUTH_TOKEN: 'inherited-oauth', CLAUDE_CODE_API_KEY_FILE_DESCRIPTOR: '3' });
  assert.equal(environment.ANTHROPIC_API_KEY, undefined);
  assert.equal(environment.CLAUDE_CODE_OAUTH_TOKEN, PLACEHOLDER);
  assert.equal(environment.CLAUDE_CODE_API_KEY_FILE_DESCRIPTOR, undefined);
});

test('it lists GH_TOKEN as the only sensitive passthrough value', () => {
  assert.deepEqual(sensitiveValues({ GH_TOKEN: 'gh', PATH: '/usr/bin', ANTHROPIC_API_KEY: 'k' }), ['gh']);
  assert.deepEqual(sensitiveValues({ GH_TOKEN: '' }), []);
});

test('it forwards the operator variables named in the start command and redacts them', () => {
  const processEnvironment = { PATH: '/usr/bin', NODE_AUTH_TOKEN: 'npm-secret', OTHER: 'kept-out', ANTHROPIC_API_KEY: 'nope', CLAUDE_CODE_OAUTH_TOKEN: 'nope' };
  const begin = { ...start(), passthroughEnv: ['NODE_AUTH_TOKEN', 'ANTHROPIC_API_KEY', 'CLAUDE_CODE_OAUTH_TOKEN', 'HOME', 'bad-name', 42] };
  const environment = buildChildEnvironment(begin, processEnvironment);

  assert.equal(environment.NODE_AUTH_TOKEN, 'npm-secret');
  assert.equal(environment.OTHER, undefined);
  assert.equal(environment.ANTHROPIC_API_KEY, undefined);
  assert.equal(environment.CLAUDE_CODE_OAUTH_TOKEN, PLACEHOLDER);
  assert.equal(environment.HOME, '/home/yak');
  assert.ok(sensitiveValues(processEnvironment, begin).includes('npm-secret'));
});
