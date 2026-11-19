#### Threads waiting for a repository (2026-11-18)

In the thread list, a thread that waits for its repository now reports `needsYouReason` `repository_question` with `needsYouLabel` "Which repository?". Before, such a row said `question` ("Asked you a question"). The label of this reason changes too: it was "Asked which repository" and is now "Which repository?".

A request that was cancelled while its thread had no repository no longer asks for one: `GET threads/{thread}` returns `repositoryQuestion: null` and no `card: "repository_question"` for it, and the thread leaves the needs-you list.
