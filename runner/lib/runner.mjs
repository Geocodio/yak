// Yak SDK runner: JSON lines on stdin, one JSON line per SDK message on stdout.
// stdin:  {type:'start',...} first, then user | interrupt | set_model | end | tool_result.
// stdout: every SDK message unchanged, plus {type:'runner', event} lifecycle lines.
import readline from 'node:readline';
import { sensitiveValues } from './environment.mjs';
import { createRedactor } from './redaction.mjs';
import { buildOptions } from './options.mjs';
import { createYakTools } from './yak-tools.mjs';

export function createRunner({ query, tool, createSdkMcpServer, input, output, exit, environment = process.env }) {
  const queue = [];
  let wake = null;
  let ended = false;
  let activeQuery = null;
  let redactor = createRedactor([]);
  let hasSecrets = false;

  const wakeUp = () => { const resolve = wake; wake = null; resolve?.(); };
  const write = (object) => output.write(redactor.redactText(JSON.stringify(object)) + '\n');
  const emit = (object) => {
    if (hasSecrets && object.type === 'stream_event') {
      const eventType = object.event?.type;
      if (eventType === 'content_block_delta') { write(redactor.withholdDelta(object)); return; }
      if (eventType === 'content_block_stop') redactor.flushHeld(object, object.event.index).forEach(write);
      else if (eventType === 'message_stop') redactor.flushHeld(object).forEach(write);
    }
    write(object);
  };
  const yak = createYakTools({ write, tool, createSdkMcpServer });
  const runnerLine = (event, extra = {}) => emit({ type: 'runner', event, ...extra });
  const describe = (error) => String(error?.stack || error?.message || error);

  async function* promptStream() {
    while (true) {
      while (queue.length) yield queue.shift();
      if (ended) return;
      await new Promise((resolve) => { wake = resolve; });
    }
  }

  async function run(start) {
    const secrets = sensitiveValues(environment, start);
    redactor = createRedactor(secrets);
    hasSecrets = secrets.length > 0;
    activeQuery = query({ prompt: promptStream(), options: buildOptions(start, environment, { yak: yak.server }) });
    runnerLine('started', { pid: process.pid });
    let sawResult = false;
    try {
      for await (const message of activeQuery) {
        if (message.type === 'result') sawResult = true;
        emit(message);
      }
    } catch (error) {
      // The CLI exits 1 after an error result; the result line already carried the outcome.
      if (!sawResult) throw error;
      runnerLine('exit_after_result', { error: describe(error) });
    }
    runnerLine('done');
  }

  function handle(command) {
    if (command.type === 'user') {
      if (!command.uuid) { runnerLine('bad_line', { error: 'user line requires uuid' }); return; }
      queue.push({
        type: 'user',
        message: { role: 'user', content: command.text },
        parent_tool_use_id: null,
        session_id: '',
        uuid: command.uuid,
        ...(command.priority ? { priority: command.priority } : {}),
      });
      wakeUp();
    } else if (command.type === 'interrupt') {
      activeQuery?.interrupt().then(() => runnerLine('interrupted'));
    } else if (command.type === 'set_model') {
      activeQuery?.setModel(command.model).catch((error) => runnerLine('error', { error: describe(error) }));
    } else if (command.type === 'tool_result') {
      yak.resolveToolResult(command);
    } else if (command.type === 'end') {
      ended = true;
      yak.rejectAll('The runner ended before Yak answered');
      wakeUp();
    }
  }

  let started = false;
  const lines = readline.createInterface({ input });
  lines.on('line', (line) => {
    if (!line.trim()) return;
    let command;
    try { command = JSON.parse(line); } catch { runnerLine('bad_line', { length: line.length }); return; }
    if (!started) {
      if (command.type !== 'start') { runnerLine('error', { error: 'first line must be start' }); exit(2); return; }
      if (typeof command.placeholder !== 'string' || typeof command.anthropicBaseUrl !== 'string') { runnerLine('error', { error: 'start requires placeholder and anthropicBaseUrl' }); exit(2); return; }
      started = true;
      run(command).then(() => exit(0), (error) => { runnerLine('error', { error: describe(error) }); exit(1); });
      return;
    }
    handle(command);
  });
  lines.on('close', () => { ended = true; yak.rejectAll('The runner ended before Yak answered'); wakeUp(); });

  return {
    shutdown() {
      ended = true;
      yak.rejectAll('The runner shut down before Yak answered');
      wakeUp();
      return Promise.resolve(activeQuery?.interrupt()).catch(() => {});
    },
  };
}
