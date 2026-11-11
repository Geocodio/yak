import { readFileSync } from 'node:fs';
import { buildChildEnvironment } from './environment.mjs';

// Wakeup and cron tools would outlive the sandbox turn, so the agent never gets them.
export const DEFAULT_DISALLOWED_TOOLS = ['ScheduleWakeup', 'CronCreate', 'CronDelete', 'CronList'];

export function buildOptions(start, environment, extraMcpServers = {}) {
  const options = {
    cwd: start.cwd || '/workspace',
    model: start.model,
    permissionMode: start.permissionMode || 'bypassPermissions',
    env: buildChildEnvironment(start, environment),
    includePartialMessages: !!start.partial,
    settingSources: start.settingSources || ['user', 'project', 'local'],
    disallowedTools: [...new Set([...DEFAULT_DISALLOWED_TOOLS, ...(start.disallowedTools ?? [])])],
  };
  if (options.permissionMode === 'bypassPermissions') options.allowDangerouslySkipPermissions = true;
  if (start.appendSystemPrompt) {
    options.systemPrompt = { type: 'preset', preset: 'claude_code', append: start.appendSystemPrompt };
  }
  if (start.resume) options.resume = start.resume;
  // The MCP servers may hold secrets, so the start line carries only the path of a file in the sandbox.
  const fileServers = start.mcpConfigPath ? JSON.parse(readFileSync(start.mcpConfigPath, 'utf8')).mcpServers ?? {} : {};
  const mcpServers = { ...(start.mcpServers ?? {}), ...fileServers, ...extraMcpServers };
  if (Object.keys(mcpServers).length) options.mcpServers = mcpServers;
  if (start.additionalDirectories) options.additionalDirectories = start.additionalDirectories;
  return options;
}
