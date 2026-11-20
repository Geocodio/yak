import { PassThrough } from 'node:stream';
import { z } from 'zod';
import { createRunner } from '../../lib/runner.mjs';

export const SECRET = 'ghp_secret_passthrough_value';
export const PLACEHOLDER = 'yakph-0123456789abcdef';
export const BASE_URL = 'http://10.0.0.1:8787';

export const start = (extra = {}) => ({
  type: 'start',
  model: 'claude-haiku-4-5',
  cwd: '/workspace',
  claudeConfigDir: '/home/yak/.claude-thread',
  placeholder: PLACEHOLDER,
  anthropicBaseUrl: BASE_URL,
  ...extra,
});

// Fake SDK surface: tool() and createSdkMcpServer() return plain descriptors the tests can inspect.
// The real tool() takes a zod raw shape, so the fake wraps it into an object schema for parse().
export const fakeTool = (name, description, shape, handler) => ({ name, description, schema: z.object(shape), handler });
export const fakeCreateSdkMcpServer = ({ name, tools }) => ({ type: 'sdk', name, instance: { tools } });

export function createHarness({ messages = [], throwAfter = null, stopTask = async () => {}, supportedCommands = [], environment = { GH_TOKEN: SECRET }, yakTools, runnerOptions = {} } = {}) {
  const input = new PassThrough();
  const written = [];
  const output = { write: (chunk) => written.push(chunk) };
  const calls = { options: null, prompts: [], setModel: [], applyFlagSettings: [], interrupts: 0, stopTask: [] };
  let release;
  let released = false;
  const gate = new Promise((resolve) => { release = () => { released = true; wakeLater?.(); resolve(); }; });
  // Messages a test sends after the run began, to interleave them with timers.
  const later = [];
  let wakeLater = null;

  const query = ({ prompt, options }) => {
    calls.options = options;
    const generator = (async function* () {
      (async () => { for await (const line of prompt) calls.prompts.push(line); })();
      for (const message of messages) yield message;
      if (throwAfter) throw new Error(throwAfter);
      while (!released) {
        if (later.length) { yield later.shift(); continue; }
        await new Promise((resolve) => { wakeLater = resolve; });
      }
    })();
    generator.setModel = async (model) => { calls.setModel.push(model); };
    generator.applyFlagSettings = async (settings) => { calls.applyFlagSettings.push(settings); };
    generator.interrupt = async () => { calls.interrupts += 1; };
    generator.supportedCommands = async () => supportedCommands;
    generator.stopTask = async (taskId) => { calls.stopTask.push(taskId); return stopTask(taskId); };
    return generator;
  };

  const exited = new Promise((resolve) => {
    createRunner({ query, input, output, exit: resolve, environment, tool: fakeTool, createSdkMcpServer: fakeCreateSdkMcpServer, yakTools, ...runnerOptions });
  });
  const send = (object) => input.write(JSON.stringify(object) + '\n');
  const lines = () => written.join('').split('\n').filter(Boolean).map((line) => JSON.parse(line));
  const tick = () => new Promise((resolve) => setTimeout(resolve, 20));

  const push = (message) => { later.push(message); wakeLater?.(); };

  return { send, lines, exited, calls, tick, release, input, written, push };
}
