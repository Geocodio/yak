#### Embedded Deployments and PR reviews pages (2026-11-24)

Additions, so the protocol stays 1. The web view can show the web's Deployments and PR reviews pages, read-only, at `/app/embed/deployments`, `/app/embed/deployments/{id}` (the 8-character public id), `/app/embed/pr-reviews` and `/app/embed/pr-reviews/{owner}/{repo}/{number}`. `POST web-session` accepts exactly these paths (plus the thread page) as `path`; a query, a fragment, a trailing slash or any other path answers 422. Everyone who can sign in sees the same deployments and reviews, as on the web.

The server announces the pages in `me.web.pages`, a list of `deployments` and `pr-reviews`; `me.web.embed` stays `1`. A client shows a tile only for a name it knows and finds in the list, and ignores other names.

The pages hide every write action (rebuild, destroy, hibernation, share link, manifest, re-run a review) and answer 404 if one is called. A link to a thread or any page outside `/app/embed/` posts `link`; the list pages link to their detail pages inside `/app/embed/`.

Bridge: `ready` from these pages is `{ url, title }` with no `threadId` or `latestSequence` (a thread page still sends all four; a message with only one of the two is malformed), posted once per address when the page has mounted. A client decodes `threadId` and `latestSequence` as optional and shows the page as ready on this message; the fixture for it is added with the app's decoder, so the existing fixtures stay unchanged.
