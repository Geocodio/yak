import { PassThrough } from 'node:stream';
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
export const fakeTool = (name, description, schema, handler) => ({ name, description, schema, handler });
export const fakeCreateSdkMcpServer = ({ name, tools }) => ({ type: 'sdk', name, instance: { tools } });

export function createHarness({ messages = [], throwAfter = null, environment = { GH_TOKEN: SECRET }, yakTools } = {}) {
  const input = new PassThrough();
  const written = [];
  const output = { write: (chunk) => written.push(chunk) };
  const calls = { options: null, prompts: [], setModel: [], interrupts: 0 };
  let release;
  const gate = new Promise((resolve) => { release = resolve; });

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
    createRunner({ query, input, output, exit: resolve, environment, tool: fakeTool, createSdkMcpServer: fakeCreateSdkMcpServer, yakTools });
  });
  const send = (object) => input.write(JSON.stringify(object) + '\n');
  const lines = () => written.join('').split('\n').filter(Boolean).map((line) => JSON.parse(line));
  const tick = () => new Promise((resolve) => setTimeout(resolve, 20));

  return { send, lines, exited, calls, tick, release, input, written };
}
