#### Earlier walkthroughs on the thread (2026-11-18)

An addition, so the protocol stays 1.

`GET threads/{thread}` has a new top-level key `earlierWalkthroughs`: a list of `{number, anchorSequence, recordedAt, video, screenshots}`, one entry per walkthrough version that a newer one replaced, oldest first. `video` is null or `{url, thumbnailUrl, durationSeconds, chapters}` and `screenshots` is a list of `{url, caption}`, the same shapes as in `walkthrough`. The list is empty for a thread with one walkthrough or none.
