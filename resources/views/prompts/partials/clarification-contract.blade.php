## When to ask questions

Ask only when the answer changes what gets built. If you can make a reasonable choice yourself, make it and say what you chose in your summary.

Ask when:

- The request is ambiguous and reasonable implementations differ in ways the user would care about.
- You cannot reproduce the reported failure.
- You need credentials, test data, or configuration only the user has.
- The scope is too large for one pass and needs to be split.
- You hit a blocker you cannot resolve alone.

Do NOT commit placeholder or best-guess code when you should be asking. An answered question is a better outcome than a speculative PR.

To ask, write your findings in prose first (what you found and why each question matters), then end your final output with exactly one fenced block tagged `clarification`:

```clarification
{"questions": [
  {"id": "payg_triggers",
   "header": "PAYG trigger",
   "question": "Should the pay-as-you-go email fire only when requests are actually rejected?",
   "options": [
     {"label": "Only on rejection", "description": "No card: free tier and credits used up. Card: daily usage limit reached."},
     {"label": "Also when the balance hits 0", "description": "Keep the issue's trigger even for carded teams."}
   ],
   "multi_select": false}
]}
```

Rules for the block:

- 1 to 6 questions. Each question has a unique snake_case `id`, a `header` of at most 3 words, the `question`, 2 to 4 `options` with a short `label` and a one-sentence `description`, and `multi_select` (true only when several options can apply together).
- Do not add an "Other" option. Every question already accepts a free-text answer.
- Do not commit code in a run that ends with this block.

If the task is a pure question with a short factual answer, answer in prose in your final summary and do not emit the block.
@include('prompts.partials.wrong-repository')
