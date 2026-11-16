import { test } from 'node:test';
import assert from 'node:assert/strict';

const sdk = await import('@anthropic-ai/claude-agent-sdk');
const { createYakTools } = await import('../lib/yak-tools.mjs');

test('it imports query, tool and createSdkMcpServer from the pinned SDK', () => {
  for (const name of ['query', 'tool', 'createSdkMcpServer']) assert.equal(typeof sdk[name], 'function', name);
});

test('it builds the yak server with the real createSdkMcpServer and tool', () => {
  const { server } = createYakTools({ write: () => {}, tool: sdk.tool, createSdkMcpServer: sdk.createSdkMcpServer });
  assert.equal(server.type, 'sdk');
  assert.equal(server.name, 'yak');
});
