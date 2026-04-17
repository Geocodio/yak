IMPORTANT: Before starting work, assess whether the following task description is clear enough to act on. If the description is ambiguous, unclear, or could be interpreted in multiple ways, respond ONLY with the following JSON format and do nothing else:

```json
{
  "clarification_needed": true,
  "options": ["Option 1 description", "Option 2 description", "Option 3 description"]
}
```

Provide 2-4 concrete options that cover the most likely interpretations. If the task is clear, proceed with the fix normally.

---

Fix the following issue reported via Slack by {{ $requesterName }}:

{{ $description }}

@include('prompts.partials.clarification-contract')