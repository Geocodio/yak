import { test } from 'node:test';
import assert from 'node:assert/strict';
import { selectLeftovers, listProcesses } from '../lib/process-sweep.mjs';

const turnStartedAtMs = 1_000_000;
const processes = [
  { pid: 1, ppid: 0, uid: 0, startedAtMs: 0, command: 'init' },
  { pid: 10, ppid: 1, uid: 1000, startedAtMs: 500_000, command: 'node runner.mjs' },
  { pid: 11, ppid: 10, uid: 1000, startedAtMs: 500_100, command: 'claude' },
  { pid: 12, ppid: 11, uid: 1000, startedAtMs: 500_200, command: 'mcp-server' },
  { pid: 20, ppid: 1, uid: 1000, startedAtMs: 1_100_000, command: 'node playwright run-server' },
  { pid: 21, ppid: 1, uid: 0, startedAtMs: 1_100_000, command: 'dockerd child' },
  { pid: 22, ppid: 1, uid: 1000, startedAtMs: 900_000, command: 'older daemon' },
  { pid: 23, ppid: 11, uid: 1000, startedAtMs: 1_100_000, command: 'bash tool shell' },
];
const select = () => selectLeftovers(processes, { agentUid: 1000, turnStartedAtMs, protectedPids: new Set([10]) });

test('it selects an orphan started after the turn', () => {
  assert.ok(select().includes(20));
});

test('it spares an MCP server started before the turn, the runner and the CLI', () => {
  assert.deepEqual(select().filter((pid) => [10, 11, 12].includes(pid)), []);
});

test('it spares processes owned by another user and pid 1', () => {
  assert.equal(select().includes(21), false);
  assert.equal(select().includes(1), false);
});

test('it spares a process started before the turn', () => {
  assert.equal(select().includes(22), false);
});

test('it spares descendants of a protected pid', () => {
  assert.equal(select().includes(23), false);
});

test('it never throws when /proc is unreadable', () => {
  assert.deepEqual(listProcesses('/nonexistent-proc'), []);
});
