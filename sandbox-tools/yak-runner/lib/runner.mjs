// Yak SDK runner: JSON lines on stdin, one JSON line per SDK message on stdout.
// stdin:  {type:'start',...} first, then user | interrupt | set_model | end.
// stdout: every SDK message unchanged, plus {type:'runner', event} lifecycle lines.
import readline from 'node:readline';
import { randomUUID } from 'node:crypto';

const PASSTHROUGH_VARIABLES = ['PATH', 'LANG', 'LC_ALL', 'TERM', 'TMPDIR', 'SHELL', 'USER', 'LOGNAME', 'GH_TOKEN'];
const STREAM_IDLE_TIMEOUT_MS = '1800000';

// The SDK replaces the child environment with options.env, so this is the whole environment
// the agent sees. Exactly one credential variable is set, from the start line.
export function buildChildEnvironment(start, processEnvironment) {
  const environment = {};
  for (const name of PASSTHROUGH_VARIABLES) {
    if (processEnvironment[name] !== undefined) environment[name] = processEnvironment[name];
  }
  environment.HOME = '/home/yak';
  environment.CLAUDE_CONFIG_DIR = start.claudeConfigDir;
  environment.CLAUDE_STREAM_IDLE_TIMEOUT_MS = STREAM_IDLE_TIMEOUT_MS;
  environment.CLAUDE_BYTE_STREAM_IDLE_TIMEOUT_MS = STREAM_IDLE_TIMEOUT_MS;
  if (start.credential?.type === 'api_key') environment.ANTHROPIC_API_KEY = start.credential.value;
  else if (start.credential?.type === 'oauth_token') environment.CLAUDE_CODE_OAUTH_TOKEN = start.credential.value;
  return environment;
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
  let secret = null;

  const wakeUp = () => { const resolve = wake; wake = null; resolve?.(); };
  const emit = (object) => {
    let line = JSON.stringify(object);
    if (secret) line = line.split(secret).join('[redacted]');
    output.write(line + '\n');
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
    secret = start.credential?.value || null;
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
    try { command = JSON.parse(line); } catch { runnerLine('bad_line', { line: line.slice(0, 200) }); return; }
    if (!started) {
      if (command.type !== 'start') { runnerLine('error', { error: 'first line must be start' }); exit(2); return; }
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
