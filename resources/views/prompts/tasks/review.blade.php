Review pull request #{{ $prNumber }} in this sandbox.

**Title:** {{ $prTitle }}
**Author:** {{ $prAuthor }}
**Base:** {{ $baseBranch }} → **Head:** {{ $headBranch }}
**Scope:** {{ $reviewScope }} review

@if ($reviewScope === 'incremental')
This is an **incremental review**. Only review files and changes in the range since the previous Yak review. Do NOT re-review code outside this range.
@endif

**Changed files (already filtered through path excludes):**
```
@foreach ($changedFiles as $f)
- {{ $f }}
@endforeach
```

**Diff summary:**
```
{{ $diffSummary }}
```

@if (! empty($repoAgentInstructions))
**Repository-specific instructions:**
{!! $repoAgentInstructions !!}
@endif

@if ($linearTicket !== null)
**Linear ticket ({{ $linearTicket['identifier'] }}):**
Title: {{ $linearTicket['title'] }}
{{ $linearTicket['description'] }}

Evaluate whether this PR accomplishes what this ticket describes. Flag drift, out-of-scope changes, or unaddressed requirements as a `should_fix` finding in the **Ticket Alignment** category.
@endif

**PR description:**
{!! $prBody !!}

---

## What to do

1. Read the changed files. For the incremental case, focus on changes in the diff range only.
2. If the repository has a test suite and you can identify how to run a subset (check `CLAUDE.md` and the instructions above), run tests relevant to the changed files. Include failures as `must_fix` findings.
3. If the repository has type checkers (`phpstan`, `tsc`) or linters (`pint`, `eslint`, `biome`), run them against changed files. Include genuine issues — skip style nits that auto-formatters will catch.
4. Evaluate findings against the rubric below.
5. Emit the JSON output block specified at the end.

## Rubric categories

- Simplicity, Test Quality, Code Duplication & Reuse, Clean Code, Code Expressiveness, Boy Scout Rule, Technology & Dependencies, Documentation, Performance & Infrastructure, Laravel Conventions, Commit Hygiene
- **Ticket Alignment** (only when a Linear ticket is attached)

## Severity buckets

- **must_fix** — blocks merge; real bug, test failure, obvious security issue
- **should_fix** — meaningful improvement but not a blocker
- **consider** — nits and suggestions; will be bundled into a collapsed "Nitpicks" block

## Rules

- Do NOT commit, push, or modify any files. This is read-only analysis.
- Maximum 20 findings total. Cull ruthlessly.
- Skip any file matching `pathExcludes`: @json($pathExcludes)
- Use ` ```suggestion ` blocks only when the change is 1–10 lines AND inside the relevant diff hunk. Populate `suggestion_loc` with the line count.
- Do not flag formatting-only issues that Pint / Prettier / Biome will auto-fix.

## Output

Emit exactly one JSON code fence at the end of your reply, with this shape:

```json
{
  "summary": "One- or two-paragraph walkthrough of what the PR does. Sized to complexity.",
  "verdict": "Approve" | "Approve with suggestions" | "Request changes",
  "verdict_detail": "One-line justification.",
  "findings": [
    {
      "file": "app/Services/Foo.php",
      "line": 87,
      "severity": "should_fix",
      "category": "Simplicity",
      "body": "Comment text. May contain a fenced suggestion block.",
      "suggestion_loc": 3
    }
  ]
}
```

If a finding is not a suggestion, omit `suggestion_loc`.
