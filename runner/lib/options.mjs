import { readFileSync } from 'node:fs';
import { buildChildEnvironment } from './environment.mjs';

// Wakeup and cron tools would outlive the sandbox turn, so the agent never gets them.
export const DEFAULT_DISALLOWED_TOOLS = ['ScheduleWakeup', 'CronCreate', 'CronDelete', 'CronList'];

export const ENVIRONMENT_NOTE_HOOK_COMMAND = 'node /opt/yak-runner/hooks/environment-note.mjs';

// canUseTool is what makes the SDK offer AskUserQuestion; without it the tool does not exist for the agent.
export function buildOptions(start, environment, extraMcpServers = {}, canUseTool = undefined) {
  const options = {
    cwd: start.cwd || '/workspace',
    model: start.model,
    permissionMode: start.permissionMode || 'bypassPermissions',
    env: buildChildEnvironment(start, environment),
    includePartialMessages: !!start.partial,
    settingSources: start.settingSources || ['user', 'project', 'local'],
    disallowedTools: [...new Set([...DEFAULT_DISALLOWED_TOOLS, ...(start.disallowedTools ?? [])])],
    settings: buildSettings(start),
  };
  if (start.effort) options.effort = start.effort;
  if (canUseTool) options.canUseTool = canUseTool;
  if (start.subagents) {
    // A stop of Claude's reply spares background agents; each one stops through stopTask.
    options.perTaskStopAffordance = true;
    options.forwardSubagentText = true;
    options.agentProgressSummaries = true;
    // Undocumented in the SDK typings, so Yak can turn it off if a CLI update removes it.
    if (start.forwardNestedSubagentToolCalls) options.forwardNestedSubagentToolCalls = true;
  }
  if (options.permissionMode === 'bypassPermissions') options.allowDangerouslySkipPermissions = true;
  if (start.appendSystemPrompt) {
    options.systemPrompt = { type: 'preset', preset: 'claude_code', append: start.appendSystemPrompt };
  }
  // A resumed session would otherwise keep sending the system prompt it recorded first: for a thread
  // teleported from a computer that is the local prompt, without Yak's rules. Rendered fresh, Yak's
  // current instructions reach every request.
  options.systemPromptSnapshot = false;
  if (start.resume) options.resume = start.resume;
  // The MCP servers may hold secrets, so the start line carries only the path of a file in the sandbox.
  const fileServers = start.mcpConfigPath ? JSON.parse(readFileSync(start.mcpConfigPath, 'utf8')).mcpServers ?? {} : {};
  const mcpServers = { ...(start.mcpServers ?? {}), ...fileServers, ...extraMcpServers };
  if (Object.keys(mcpServers).length) options.mcpServers = mcpServers;
  if (start.additionalDirectories) options.additionalDirectories = start.additionalDirectories;
  return options;
}

// The hook goes in through options.settings, not a file in the config directory, because that directory is synced to the session store.
function buildSettings(start) {
  const caller = start.settings ?? {};
  const hook = {
    matcher: 'resume',
    hooks: [{ type: 'command', command: start.environmentNoteHookCommand || ENVIRONMENT_NOTE_HOOK_COMMAND }],
  };
  return { ...caller, hooks: { ...caller.hooks, SessionStart: [hook, ...(caller.hooks?.SessionStart ?? [])] } };
}
