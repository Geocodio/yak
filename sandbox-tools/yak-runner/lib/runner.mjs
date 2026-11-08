// Yak SDK runner: JSON lines on stdin, one JSON line per SDK message on stdout.
// stdin:  {type:'start',...} first, then user | interrupt | set_model | end.
// stdout: every SDK message unchanged, plus {type:'runner', event} lifecycle lines.
import readline from 'node:readline';

const PASSTHROUGH_VARIABLES = ['PATH', 'LANG', 'LC_ALL', 'TERM', 'TMPDIR', 'SHELL', 'USER', 'LOGNAME', 'GH_TOKEN'];
// Passthrough variables whose values must never reach the output. The agent can read these
// (it needs GH_TOKEN for git and gh), so redaction here is defence in depth only.
const SENSITIVE_PASSTHROUGH_VARIABLES = ['GH_TOKEN'];
const DELTA_FIELDS = ['text', 'thinking', 'partial_json'];
const STREAM_IDLE_TIMEOUT_MS = '1800000';

// The SDK replaces the child environment with options.env, so this is the whole environment
// the agent sees. The CLI authenticates with the thread's placeholder against the host's auth
// proxy, which swaps in the real credential. No real credential exists inside the sandbox.
export function buildChildEnvironment(start, processEnvironment) {
  const environment = {};
  for (const name of PASSTHROUGH_VARIABLES) {
    if (processEnvironment[name] !== undefined) environment[name] = processEnvironment[name];
  }
  environment.HOME = '/home/yak';
  environment.CLAUDE_CONFIG_DIR = start.claudeConfigDir;
  environment.CLAUDE_STREAM_IDLE_TIMEOUT_MS = STREAM_IDLE_TIMEOUT_MS;
  environment.CLAUDE_BYTE_STREAM_IDLE_TIMEOUT_MS = STREAM_IDLE_TIMEOUT_MS;
  environment.ANTHROPIC_BASE_URL = start.anthropicBaseUrl;
  environment.CLAUDE_CODE_OAUTH_TOKEN = start.placeholder;
  environment.CLAUDE_CODE_DISABLE_NONESSENTIAL_TRAFFIC = '1';
  return environment;
}

export function sensitiveValues(processEnvironment) {
  const values = SENSITIVE_PASSTHROUGH_VARIABLES.map((name) => processEnvironment[name]);
  return values.filter((value) => typeof value === 'string' && value !== '');
}

function buildOptions(start, environment) {
  const options = {
    cwd: start.cwd || '/workspace',
    model: start.model,
    permissionMode: start.permissionMode || 'bypassPermissions',
    env: buildChildEnvironment(start, environment),
    includePartialMessages: !!start.partial,
    settingSources: start.settingSources || ['user', 'project', 'local'],
  };
  if (options.permissionMode === 'bypassPermissions') options.allowDangerouslySkipPermissions = true;
  if (start.appendSystemPrompt) {
    options.systemPrompt = { type: 'preset', preset: 'claude_code', append: start.appendSystemPrompt };
  }
  if (start.resume) options.resume = start.resume;
  if (start.mcpServers) options.mcpServers = start.mcpServers;
  if (start.additionalDirectories) options.additionalDirectories = start.additionalDirectories;
  return options;
}

export function createRunner({ query, input, output, exit, environment = process.env }) {
  const queue = [];
  let wake = null;
  let ended = false;
  let activeQuery = null;
  let secrets = [];
  // Text withheld from partial deltas because it could be the start of a secret, by block and field.
  const held = new Map();

  const wakeUp = () => { const resolve = wake; wake = null; resolve?.(); };
  const redact = (text) => secrets.reduce((result, secret) => result.split(secret).join('[redacted]'), text);
  const write = (object) => output.write(redact(JSON.stringify(object)) + '\n');

  // A secret can be split across streamed deltas, so the longest tail of a delta that is the
  // start of a secret is held back until the next delta shows whether it completes one.
  const heldTail = (text) => {
    for (let length = Math.min(text.length, Math.max(...secrets.map((secret) => secret.length - 1))); length > 0; length--) {
      const tail = text.slice(-length);
      if (secrets.some((secret) => secret.startsWith(tail))) return length;
    }
    return 0;
  };
  const withholdDelta = (message) => {
    const { index, delta } = message.event;
    const field = DELTA_FIELDS.find((name) => typeof delta?.[name] === 'string');
    if (!field) return message;
    const key = `${index}:${field}`;
    const text = redact((held.get(key)?.text ?? '') + delta[field]);
    const keep = heldTail(text);
    held.set(key, { text: text.slice(text.length - keep), type: delta.type });
    return { ...message, event: { ...message.event, delta: { ...delta, [field]: text.slice(0, text.length - keep) } } };
  };
  const flushHeld = (message, index) => {
    for (const [key, { text, type }] of [...held]) {
      const [heldIndex, field] = key.split(':');
      if (index !== undefined && Number(heldIndex) !== index) continue;
      held.delete(key);
      if (text) write({ ...message, event: { type: 'content_block_delta', index: Number(heldIndex), delta: { type, [field]: text } } });
    }
  };
  const emit = (object) => {
    if (secrets.length && object.type === 'stream_event') {
      const eventType = object.event?.type;
      if (eventType === 'content_block_delta') { write(withholdDelta(object)); return; }
      if (eventType === 'content_block_stop') flushHeld(object, object.event.index);
      else if (eventType === 'message_stop') flushHeld(object);
    }
    write(object);
  };
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
    secrets = sensitiveValues(environment);
    activeQuery = query({ prompt: promptStream(), options: buildOptions(start, environment) });
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
    } else if (command.type === 'end') {
      ended = true;
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
  lines.on('close', () => { ended = true; wakeUp(); });

  return {
    shutdown() {
      ended = true;
      wakeUp();
      return Promise.resolve(activeQuery?.interrupt()).catch(() => {});
    },
  };
}
