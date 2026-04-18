**DIRECTOR'S CUT MODE.** This recording is for an external demo: polished, slightly slower, more explanation. Treat the audience as possibly non-technical.

Director's Cut plan rules (stricter than Reviewer):
- `tier` must be `"director"`.
- `chapters`: 4–8 entries (more than Reviewer). First `Intro`, last `Result`.
- `expected_duration_seconds`: 60–240.
- `emphasize_budget`: ≤ 6. `callout_budget`: ≤ 6.

Director's Cut narrative rules:
- Open with a short `chapter "Intro"` that frames the problem in one sentence, not just "this is the dashboard."
- Use `callout` generously to introduce UI concepts the audience won't recognize — label elements even if they are "obvious" to an engineer.
- Use `fastforward` on anything that takes more than 2 seconds (not 3) — pacing matters more in a long-form demo.
- Close with a `chapter "Result"` that shows the end state AND explains WHY it matters.

When in doubt, write `narrate` lines that sound like a tutorial host, not a debugger dumping events.
