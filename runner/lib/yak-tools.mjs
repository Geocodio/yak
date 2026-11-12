import { z } from 'zod';

export const TOOL_CALL_TIMEOUT_MS = 120000;

const LOCAL_SESSION_NOTE = 'A local machine session answers "teleport this session to Yak first".';

// In-process MCP server. Each tool call becomes a yak.tool_call line on stdout and waits for the
// matching tool_result command on stdin; an unanswered call resolves to an error after timeoutMs.
export function createYakTools({ write, timeoutMs = TOOL_CALL_TIMEOUT_MS, randomUuid = () => crypto.randomUUID(), tool, createSdkMcpServer }) {
  const pending = new Map();

  const textResult = (text, isError) => ({ content: [{ type: 'text', text }], ...(isError ? { isError: true } : {}) });

  const call = (name, input) => new Promise((resolve) => {
    const callId = randomUuid();
    const timer = setTimeout(() => {
      pending.delete(callId);
      resolve(textResult(`${name} timed out after ${timeoutMs} ms without an answer from Yak`, true));
    }, timeoutMs);
    timer.unref?.();
    pending.set(callId, { resolve, timer });
    write({ type: 'yak.tool_call', call_id: callId, tool: name, input });
  });

  const define = (name, description, shape) => tool(name, `${description} ${LOCAL_SESSION_NOTE}`, shape, (input) => call(name, input));

  const tools = [
    define('spawn_thread', 'Start a new thread with its own sandbox and a brief.', {
      name: z.string(),
      brief: z.string(),
      kind: z.enum(['implement', 'mockup', 'research', 'general']).default('general'),
      model: z.string().optional(),
      repo: z.string().optional(),
    }),
    define('send_message', 'Send a message to another thread in this project.', { to: z.string(), text: z.string() }),
    define('list_threads', 'List the threads in this project.', {}),
    define('add_repository', 'Add a repository to this thread. Use role changes when you will edit it, context for read only.', { repo: z.string(), role: z.enum(['changes', 'context']).default('changes'), reason: z.string().optional() }),
    define('set_merge_order', 'Set the order to merge this thread\'s pull requests, first to merge first. List each repository once.', { repos: z.array(z.string()) }),
    define('request_refresh', 'Ask Yak to refresh this sandbox environment.', { reason: z.string().optional() }),
  ];

  const settle = (callId, result) => {
    const entry = pending.get(callId);
    if (!entry) return;
    pending.delete(callId);
    clearTimeout(entry.timer);
    entry.resolve(result);
  };

  return {
    server: createSdkMcpServer({ name: 'yak', tools }),
    resolveToolResult(command) {
      const text = typeof command.content === 'string' ? command.content : JSON.stringify(command.content ?? '');
      settle(command.call_id, textResult(text, !!command.is_error));
    },
    rejectAll(message) {
      for (const callId of [...pending.keys()]) settle(callId, textResult(message, true));
    },
  };
}
