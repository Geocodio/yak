const PASSTHROUGH_VARIABLES = ['PATH', 'LANG', 'LC_ALL', 'TERM', 'TMPDIR', 'SHELL', 'USER', 'LOGNAME', 'GH_TOKEN'];
// Passthrough variables whose values must never reach the output. The agent can read these
// (it needs GH_TOKEN for git and gh), so redaction is defence in depth only.
const SENSITIVE_PASSTHROUGH_VARIABLES = ['GH_TOKEN'];
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
