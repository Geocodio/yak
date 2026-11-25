#### Review tab in the thread list (2026-11-24)

Additions under spec 10.3, so the protocol stays 1. An older app that never sends `filter=review` and ignores unknown keys keeps working unchanged.

`GET threads` accepts `filter=review` (it answered 422 before). It answers the Review tab for the phone's person with the web's rules: the open threads they may see (their own, shared with them or co-owned), with an open pull request, grouped by where the thread sits in their review queue. Other people's private threads never appear, in rows or in the count. Unknown `filter` values still answer 422 `validation_failed`. Search, `sources` and `starters` apply as on the other tabs.

Under `filter=review`, `starred`, `needsYou` and `threads` are empty and the rows come in `reviewGroups`, always four entries in this order, empty ones included with `rows: []`:

| `key` | `label` |
| --- | --- |
| `back_to_you` | Back to you |
| `not_reviewed` | Not reviewed |
| `with_yak` | With Yak |
| `approved` | Approved by you |

Clients show the server's `label` and treat a `key` they do not know as one more group, never as an error. Rows have the normal list row shape (`type: "thread"`, title, source, status, times, `children`) plus `review`:

```json
"review": {
  "pullRequests": [
    {"number": 12, "url": "https://github.com/example/web/pull/12", "repository": "example/web", "isDraft": false,
     "mergeability": "conflicts", "checks": "failing", "approvedByYou": false}
  ],
  "hasNewCommitsSinceApproval": false,
  "otherApprovals": 1
}
```

`pullRequests` lists the thread's open pull requests in merge order. `mergeability` is `conflicts`, `no_conflicts` or `unknown`, and only says whether the pull request has merge conflicts; it never means "ready to merge"; `checks` is `passing`, `failing`, `pending` or `null`, the same words as `pullRequests[].checks` in `GET threads/{id}`; `approvedByYou` is true when the person's approval is on the current head commit; `hasNewCommitsSinceApproval` is true when they approved an older commit; `otherApprovals` counts other people with an approval. Clients treat any unknown value as "show the label" or hide the badge. Everything is read from stored facts; the request makes no GitHub call.

Every `GET threads` answer (all filters) gains:

- `reviewCount`: the Review tab badge, the number of threads in Back to you plus Not reviewed. It ignores search and the other filters.
- `needsGitHubLink`: true when the person's GitHub login is not linked, so the Review tab is empty. Show "Link your GitHub account".
- `gitHubLinkPath`: the web path where it is linked (`/settings/profile`), opened in the web view or browser.

New field: `me.threadFilters` lists the filters `GET threads` accepts (`mine`, `yak`, `everyone`, `closed`, `review`). A client that does not find the key must not offer the Review tab.

Contract fixtures: `threads-review` is new; `threads` and `me` only gained the keys above.
