#### Overview and sub-threads (2026-11-16)

Additions under spec 10.3, so the protocol stays 1.

New endpoints: `GET threads/{thread}/stack` (from a root or from any of its sub-threads) and `GET threads/{thread}/environment`. Both answer a hidden thread with the 404 of an unknown id, and a share recipient can read both.

Contract fixtures: `stack`, `stack-child`, `stack-empty` and `thread-environment`.
