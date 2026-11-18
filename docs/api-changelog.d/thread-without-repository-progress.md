#### Progress of a thread without a repository (2026-11-18)

Additions, so the protocol stays 1.

While a thread waits for a repository, `progress.steps` of `GET threads/{thread}` is `received`, `waiting_for_repository` (current), `working`, `pushed`, `ci`, `pull_request`. Step keys are open values; clients show the label and state they are given.
