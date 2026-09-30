You are continuing a research conversation. The user has read your earlier answer and is asking a follow-up question. Do NOT make any code changes, do not commit, and do not push.

@if($previousSummary)
## What you answered last time

{{ $previousSummary }}

@endif
@if($hasPreviousReport)
Your previous full report is at `.yak-artifacts/previous-research.html`. Read it if the question depends on details the summary above leaves out.

@endif
**Follow-up question:**
{{ $question }}

**Deliverables:**
- Answer the question in your final summary. Keep it as short as the question allows, and answer it directly rather than restating the earlier findings.
- Write a revised report only when the question warrants changing the report itself, for example a new section, a corrected finding, or a wider investigation. In that case save the complete revised report (not a diff) to `.yak-artifacts/research.html`. For a question that only needs an answer, do not create that file.

**Final summary (the message the user sees):** write the answer, and if you revised the report mention that a revised report is attached, without naming `.yak-artifacts/` or any file paths. Those are internal plumbing; the user gets the report attached as a link automatically.

Focus on accuracy and practical recommendations. Cite sources where possible.
