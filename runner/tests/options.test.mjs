import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { buildOptions } from '../lib/options.mjs';
import { start } from './helpers/fake-sdk.mjs';

test('it passes disallowedTools ScheduleWakeup CronCreate CronDelete CronList even when start omits them', () => {
  assert.deepEqual(buildOptions(start(), {}).disallowedTools, ['ScheduleWakeup', 'CronCreate', 'CronDelete', 'CronList']);
  assert.deepEqual(buildOptions(start({ disallowedTools: ['CronList', 'WebSearch'] }), {}).disallowedTools, ['ScheduleWakeup', 'CronCreate', 'CronDelete', 'CronList', 'WebSearch']);
});

test('it never passes budget or turn limits', () => {
  const options = buildOptions(start({ maxTurns: 3, maxBudgetUsd: 1 }), {});
  assert.equal('maxTurns' in options, false);
  assert.equal('maxBudgetUsd' in options, false);
});

test('it sets bypassPermissions with allowDangerouslySkipPermissions', () => {
  const options = buildOptions(start(), {});
  assert.equal(options.permissionMode, 'bypassPermissions');
  assert.equal(options.allowDangerouslySkipPermissions, true);
});

test('it reads mcp servers from the file named by mcpConfigPath', () => {
  const path = join(mkdtempSync(join(tmpdir(), 'mcp-')), 'mcp-config.json');
  writeFileSync(path, JSON.stringify({ mcpServers: { linear: { command: 'x' } } }));
  const options = buildOptions(start({ mcpConfigPath: path, mcpServers: { inline: { command: 'y' } } }), {}, { yak: { type: 'sdk' } });
  assert.deepEqual(options.mcpServers, { inline: { command: 'y' }, linear: { command: 'x' }, yak: { type: 'sdk' } });
});

test('it sets systemPrompt preset claude_code with the append text', () => {
  assert.deepEqual(buildOptions(start({ appendSystemPrompt: 'extra' }), {}).systemPrompt, { type: 'preset', preset: 'claude_code', append: 'extra' });
});

test('it passes resume only when set', () => {
  assert.equal('resume' in buildOptions(start(), {}), false);
  assert.equal(buildOptions(start({ resume: 'sess-1' }), {}).resume, 'sess-1');
});

test('it registers a SessionStart command hook through options.settings', () => {
  const hook = buildOptions(start(), {}).settings.hooks.SessionStart[0];
  assert.equal(hook.matcher, 'resume');
  assert.deepEqual(hook.hooks[0], { type: 'command', command: 'node /opt/yak-runner/hooks/environment-note.mjs' });
  assert.equal(buildOptions(start({ environmentNoteHookCommand: 'node x.mjs' }), {}).settings.hooks.SessionStart[0].hooks[0].command, 'node x.mjs');
});

test('it merges caller settings with the hook instead of replacing them', () => {
  const callerHook = { matcher: 'startup', hooks: [{ type: 'command', command: 'true' }] };
  const { settings } = buildOptions(start({ settings: { effortLevel: 'low', hooks: { SessionStart: [callerHook], Stop: [] } } }), {});
  assert.equal(settings.effortLevel, 'low');
  assert.deepEqual(settings.hooks.Stop, []);
  assert.equal(settings.hooks.SessionStart.length, 2);
  assert.equal(settings.hooks.SessionStart[0].matcher, 'resume');
  assert.deepEqual(settings.hooks.SessionStart[1], callerHook);
});
