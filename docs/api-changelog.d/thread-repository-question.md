#### Threads waiting for a repository (2026-11-18)

In the thread list, a thread that waits for its repository now reports `needsYouReason` `repository_question` with `needsYouLabel` "Which repository?". Before, such a row said `question` ("Asked you a question"). The earlier fragment listed the label as "Asked which repository"; the label the API has always returned for this reason is "Which repository?".

A request that was cancelled while its thread had no repository no longer asks for one: `GET threads/{thread}` returns `repositoryQuestion: null` and no `card: "repository_question"` for it, and the thread leaves the needs-you list.
