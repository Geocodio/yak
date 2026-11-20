// Bridges Claude's AskUserQuestion tool to Yak. The SDK offers the tool only when canUseTool is set,
// so every other tool is allowed here with its input unchanged. A question becomes a runner line and
// waits for answer_question or decline_question; every way a wait can end settles the promise.
export const QUESTION_TOOL = 'AskUserQuestion';

export function createQuestionBridge({ write, randomUuid = () => crypto.randomUUID() }) {
  const pending = new Map();

  const settle = (id, result, reason) => {
    const entry = pending.get(id);
    if (!entry) return false;
    pending.delete(id);
    entry.signal?.removeEventListener('abort', entry.onAbort);
    write({ type: 'runner', event: 'question_closed', id, reason });
    entry.resolve(result);
    return true;
  };

  const deny = (message) => ({ behavior: 'deny', message });

  const ask = (input, { toolUseID, signal } = {}) => new Promise((resolve) => {
    const id = randomUuid();
    const onAbort = () => settle(id, deny('The turn was interrupted before the person answered.'), 'aborted');
    pending.set(id, { resolve, input, signal, onAbort });
    if (signal?.aborted) { onAbort(); return; }
    signal?.addEventListener('abort', onAbort, { once: true });
    write({ type: 'runner', event: 'question', id, toolUseId: toolUseID ?? null, questions: input?.questions ?? [] });
  });

  return {
    canUseTool(toolName, input, options) {
      if (toolName !== QUESTION_TOOL) return Promise.resolve({ behavior: 'allow', updatedInput: input });
      return ask(input, options);
    },
    answer({ id, answers }) {
      const entry = pending.get(id);
      if (!entry) return;
      settle(id, { behavior: 'allow', updatedInput: { questions: entry.input.questions, answers: answers ?? {} } }, 'answered');
    },
    decline({ id, message }) {
      settle(id, deny(typeof message === 'string' && message !== '' ? message : 'The person declined to answer.'), 'declined');
    },
    // The turn or the runner is over: nothing will answer these.
    rejectAll(message, reason = 'ended') {
      for (const id of [...pending.keys()]) settle(id, deny(message), reason);
    },
  };
}
