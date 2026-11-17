// Yak SDK runner: JSON lines on stdin, one JSON line per SDK message on stdout.
// stdin:  {type:'start',...} first, then user | interrupt | set_model | set_effort | end | tool_result.
// stdout: every SDK message unchanged, plus {type:'runner', event} lifecycle lines.
import readline from 'node:readline';
import { sensitiveValues } from './environment.mjs';
import { createRedactor } from './redaction.mjs';
import { buildOptions } from './options.mjs';
import { createYakTools } from './yak-tools.mjs';
import { listProcesses as listSystemProcesses, selectLeftovers } from './process-sweep.mjs';

const SWEEP_DELAY_MS = 60_000;
const KILL_GRACE_MS = 5_000;
// /proc start times have one-second resolution, so a process started right after the turn began can read slightly early.
const START_TIME_TOLERANCE_MS = 1_000;

export function createRunner({ query, tool, createSdkMcpServer, input, output, exit, environment = process.env,
  listProcesses = listSystemProcesses, kill = process.kill.bind(process), setTimer = setTimeout, clearTimer = clearTimeout, now = Date.now,
  agentUid = process.getuid?.() }) {
  const queue = [];
  let wake = null;
  let ended = false;
  let activeQuery = null;
  let redactor = createRedactor([]);
  let hasSecrets = false;
  let turnStartedAtMs = null;
  let sweepTimer = null;
  let killTimer = null;

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

  const cancelSweep = () => { if (sweepTimer) clearTimer(sweepTimer); sweepTimer = null; };

  // The turn finished but the SDK has not emitted a result: a process the agent left running
  // (for example a test server holding a pipe open) can keep the turn alive, so stop it.
  function sweep() {
    sweepTimer = null;
    try {
      if (turnStartedAtMs === null || agentUid === undefined) return;
      const processes = listProcesses();
      // The runner protects itself and, through it, the CLI and every MCP server below it.
      const pids = selectLeftovers(processes, { agentUid, turnStartedAtMs: turnStartedAtMs - START_TIME_TOLERANCE_MS, protectedPids: new Set([process.pid]) });
      if (pids.length === 0) return;
      const commands = pids.map((pid) => processes.find((entry) => entry.pid === pid)?.command ?? '');
      const signal = (targets, name) => targets.forEach((pid) => { try { kill(pid, name); } catch { /* already gone */ } });
      signal(pids, 'SIGTERM');
      const startedAt = new Map(processes.map((entry) => [entry.pid, entry.startedAtMs]));
      killTimer = setTimer(() => {
        killTimer = null;
        try {
          // Only a pid that still has the same start time is the same process, never a recycled pid.
          const survivors = new Set(listProcesses().filter((entry) => startedAt.get(entry.pid) === entry.startedAtMs).map((entry) => entry.pid));
          signal(pids.filter((pid) => survivors.has(pid)), 'SIGKILL');
        } catch { /* the sweep must never disturb the message loop */ }
      }, KILL_GRACE_MS);
      runnerLine('swept', { pids, commands });
    } catch { /* the sweep must never disturb the message loop */ }
  }

  function trackTurn(message) {
    if (message.type === 'result') { cancelSweep(); return; }
    // Subagent messages carry parent_tool_use_id; only the main agent's answer ends the turn.
    if (message.type !== 'assistant' || message.parent_tool_use_id) return;
    const blocks = Array.isArray(message.message?.content) ? message.message.content : [];
    if (turnStartedAtMs === null && blocks.some((block) => block?.type === 'tool_use')) turnStartedAtMs = now();
    cancelSweep();
    // The final answer is a text-only message; the stream reports its stop_reason as null.
    if (blocks.some((block) => block?.type === 'text') && !blocks.some((block) => block?.type === 'tool_use')) sweepTimer = setTimer(sweep, SWEEP_DELAY_MS);
  }

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
        trackTurn(message);
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
      turnStartedAtMs = null;
      cancelSweep();
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
    } else if (command.type === 'set_effort') {
      activeQuery?.applyFlagSettings({ effortLevel: command.effort ?? null }).catch((error) => runnerLine('error', { error: describe(error) }));
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
      cancelSweep();
      if (killTimer) clearTimer(killTimer);
      ended = true;
      yak.rejectAll('The runner shut down before Yak answered');
      wakeUp();
      return Promise.resolve(activeQuery?.interrupt()).catch(() => {});
    },
  };
}
