The person asked for a walkthrough video and screenshots of the change already on this branch. Do not change any code unless the capture shows the feature is broken.

Follow the VISUAL CAPTURE rules in your system prompt exactly:

1. Phase A: start the dev server, log in if needed, and confirm the change works on the real feature surface.
2. Phase B: write `.yak-artifacts/script.json`, then record the shots with `yak-browser`, and capture the screenshots with captions in `.yak-artifacts/screenshots/`.
3. Use `git diff` against the default branch to decide which pages and flows the change touches.

Stop once `.yak-artifacts/` holds the script, manifest, shots and screenshots. Yak renders the cut and attaches it to the thread and the pull request. If the change has no visible surface, say so in one sentence and write nothing.
