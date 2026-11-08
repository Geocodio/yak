// Entry point: wires the real SDK, stdin and stdout into the runner.
import { query } from '@anthropic-ai/claude-agent-sdk';
import { createRunner } from './lib/runner.mjs';

const runner = createRunner({ query, input: process.stdin, output: process.stdout, exit: (code) => process.exit(code) });
for (const signal of ['SIGTERM', 'SIGINT']) {
  process.on(signal, () => runner.shutdown().finally(() => process.exit(143)));
}
