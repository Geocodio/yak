#### GitHub reviews on thread messages (2026-11-25)

Additions, so the protocol stays 1.

Each item of `messages` on `GET threads/{thread}` has a new `review` field: null, or a GitHub review shown in place of `bodyHtml`. It is `{reviewer, avatarUrl, state, summaryHtml, url, comments}`. `state` is `approved`, `changes_requested`, `commented`, or empty for loose pull request comments. `url` links the review, or the pull request when there is no review. Each comment is `{author, bodyHtml, file, line, diff, url}`: `diff` holds the last four lines of the hunk the comment sits on, each starting with `+`, `-` or a space, and `url` links the comment's thread, or is null for a comment outside the diff. A message with a review has `via` pointing at the review with the pull request number as `reference`. Messages from before this field existed have `review: null`.

New fixture: `thread-review`. Regenerated: `thread`, `thread-attachments`.
