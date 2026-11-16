import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { createHarness, start } from './helpers/fake-sdk.mjs';

const directory = fileURLToPath(new URL('../../tests/Fixtures/runner-streams/', import.meta.url));
const isSdkMessage = (line) => !('_driver_sent' in line) && line.type !== 'runner' && line.type !== 'yak.tool_call' && !String(line.type).startsWith('broker.');

for (const name of readdirSync(directory).filter((file) => file.endsWith('.jsonl'))) {
  test(`it replays ${name} with every SDK message unchanged`, async () => {
    const [, ...rest] = readFileSync(directory + name, 'utf8').split('\n').filter(Boolean).map((line) => JSON.parse(line));
    const messages = rest.map(({ _ms, ...line }) => line).filter(isSdkMessage);
    const run = createHarness({ messages, environment: {} });
    run.send(start());
    await run.tick();
    assert.deepEqual(run.lines().filter((line) => line.type !== 'runner'), messages);
    run.release();
    run.send({ type: 'end' });
    await run.exited;
  });
}
