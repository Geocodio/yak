
## When to ask for clarification

If you cannot make progress on this task, you MUST emit `clarificationNeeded: true` in your final output, along with either a concrete question or a short list of `clarificationOptions` the user can pick from. Reasons this applies:

- The request is ambiguous and multiple reasonable implementations are possible.
- You cannot reproduce the reported failure.
- You need credentials, test data, or configuration only the user has.
- The scope is too large for one pass and needs to be split.
- You attempted the task and hit a blocker that you cannot resolve alone.

Do NOT commit placeholder or best-guess code when you should be asking. An answered question is a better outcome than a speculative PR.

If the task is a pure question with a short factual answer, answer in prose in your final summary and do not commit code — the pipeline will treat that as a successful answer.
