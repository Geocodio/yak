**USE `yak-browser`, NEVER `agent-browser` DIRECTLY.** `yak-browser` is a superset: every `agent-browser` command works through it, plus the walkthrough commands below. If the `agent-browser` skill is loaded, ignore its CLI references in the context of Yak walkthroughs.

   **Two distinct phases — do not mix them.** Phase A is you making the feature work. Phase B is a scripted, shot-by-shot capture that Yak edits into one cut. **The capture must happen on the REAL feature surface — the actual page or flow a user will see. A standalone demo page is a last resort, not a shortcut.**

   **PHASE A — Implement and verify (nothing is recorded).**
   a. Start the dev server (read CLAUDE.md/README for how).
   b. If authentication is needed, read CLAUDE.md/README or seeder files for test credentials. Log in using `yak-browser`.
   c. Navigate to the real feature surface, interact with it, and confirm it works end-to-end. Apply any stubs/scaffolding here (stub external calls with `Http::fake`, swap a service binding, seed a test record, add a dev-only auth shortcut). Ad-hoc screenshots (`yak-browser screenshot /tmp/check.png`) are throwaway — do NOT put them in `.yak-artifacts/`.
   d. If a page you are about to inspect or capture looks unstyled, run `yak-browser assets check --base <url>` first. Exit 4 means the built frontend assets are missing or older than the sources.
   e. Only proceed to Phase B once the feature works on the real surface and you know the exact sequence of steps a user would take to see it.

   **PHASE B — Write the script, review it, shoot it.**

   Write `.yak-artifacts/script.json`:

   ```json
   {
     "version": 3,
     "title": "One line, at most 90 characters, naming what changed",
     "intro": "Two sentences, at most 240 characters, saying what this change does for a user.",
     "summary": ["2 to 5 bullets", "at most 60 characters each"],
     "outro": "One or two sentences, at most 160 characters, closing the video.",
     "shots": [
       {
         "id": "levels",
         "chapter": "Geography levels",
         "say": "What the viewer can SEE at the end of this shot. At most 180 characters and 32 words.",
         "do": [
           { "navigate": "/guides/demographics-census/" },
           { "scroll_to": "ul:has(li:has-text('Census Region'))" }
         ],
         "focus": "ul:has(li:has-text('Census Region'))"
       }
     ],
     "screenshots": [
       { "id": "zip-section", "caption": "New ZIP-level section with the no-ZCTA warning", "after_shot": "levels" }
     ]
   }
   ```

   Script rules (the linter enforces every one of them):
   - 3–12 shots. Each `id` is a unique slug. Each shot has at least one physical action in `do` — a shot made only of `wait` is rejected.
   - Actions: `navigate` (path relative to the base URL, or absolute), `scroll_to`, `click`, `fill` (selector + value), `type`, `press`, `wait` (selector or ms, capped at 5000), `hover`. 1–6 per shot.
   - Every selector in `do` and `focus` must resolve on the page that shot ends on.
   - `chapter` titles: 2–5 distinct titles, in order, contiguous (all the shots of one chapter sit together). `Intro`, `Result` and `Before` are reserved.
   - `say` describes what is on screen at the END of the shot — you are a tutorial host, not a log line. Never narrate the click you are about to make; narrate the state the viewer is looking at.
   - `screenshots`: 1–5 entries, unique ids, captions at most 100 characters saying what the reviewer is looking at. Pick genuinely different states or pages — a form before and after submit, two pages the change touches. `after_shot` names the shot after whose hold the still is taken; an entry without `after_shot` needs its own `do` list.
   - No text field may contain a localhost or preview hostname, or the word "Yak".

   Then, in order:

   ```
   yak-browser script .yak-artifacts/script.json --base <url> --review
   ```

   `--review` prints the script with a three-question checklist: does the intro say what changed; does every shot show something the diff touched; would a reviewer know where to look. Answer all three to yourself and edit the script if any answer is no. Exit 2 means lint errors — read them, fix the script, run it again until it is clean. Exit 4 means the frontend assets are stale or broken: rebuild the frontend assets the way this repository's setup notes describe (or start its dev server) and re-run. Rebuild when `assets check` fails, or when your diff touched `package.json`, a lockfile, or an asset directory. Never rebuild unconditionally.

   ```
   yak-browser shoot .yak-artifacts/script.json --base <url>
   ```

   The shoot drives the browser itself — one clip per shot, with a synthetic cursor, eased scrolling and a hold at the end of each shot. You do not drive it interactively. It writes `shots/*.webm`, `stills/*.png`, `screenshots/*.png` and `manifest.json` into `.yak-artifacts/`.

   Exit 3 means a shot failed twice. The message names the shot, the reason, and the exact re-run command. Fix that shot in the script and run that command: `--from <id>` re-shoots the failed shot and every shot after it, `--only <id>` re-shoots one shot. Both keep the shots already recorded in `manifest.json`. If the shoot still cannot complete, report `Visual capture: partial — <reason>` and move on; the task is not blocked by the video.

   **Verify and finish:**

   ```
   ls -la .yak-artifacts/ .yak-artifacts/shots/
   ```

   Confirm `script.json`, `manifest.json`, one `shots/<id>.webm` per shot, and your `screenshots/*.png` are present.

   **Rules that apply to both phases:**
   - If something blocks a *full* capture (dev server won't start, auth genuinely can't be bypassed, an external dependency truly can't be reached), capture what you CAN reach. Never silently skip. Never fall back to a standalone demo page without first trying: (1) stub external calls (`Http::fake`, service bindings, canned responses); (2) seed test data or add a dev-only auth bypass. Keep scaffolding in place for the shoot; revert only after the shoot and before `git commit`.
   - Run `git diff --stat` before committing; confirm only intended files are staged. Yak's sandbox `.gitignore` excludes `.yak-artifacts/`, so do NOT `git add` capture files — Yak collects them out-of-band and attaches them to the PR automatically.
   - Stop the dev server when done — background processes prevent the task from completing.
   - REQUIRED STATUS LINE: End the result summary with exactly one of these lines — no exceptions:
      - `Visual capture: done (real flow)` — shot on the actual feature surface, including when external calls were stubbed.
      - `Visual capture: done (isolated harness) — <why the real surface was not capturable>`
      - `Visual capture: partial — <what was captured and what wasn't>`
      - `Visual capture: skipped — <specific reason>`
      A missing line is a task violation. Silent skipping is not allowed.
