#### Question attachments (2026-11-23)

Additions under spec 10.3, so the protocol stays 1.

Each entry of `question.questions` (thread and task responses) gains `context`, a list of attachments Claude added to the whole question. Each option gains `attachment` (an attachment or `null`) and `previewHtml` (rendered markdown, or `null`). An attachment is `{id, name, alt, kind, url, rawUrl}`, where `kind` is the shared file kind (`report`, `image`, `csv`, `pdf`, `text` or `file`, open for new values), `url` opens the shared file page and `rawUrl` serves its bytes. `question` no longer contains the `![caption](file)` references the attachments came from.

Contract fixtures: `task-question`, `thread-question`.
