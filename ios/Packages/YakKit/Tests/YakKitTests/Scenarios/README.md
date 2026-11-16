Hand-written scenario data for the engine tests. Kept for good. Server contract fixtures live in `tests/Fixtures/app-api/v1/` and are read only by the decode tests in `FixtureDecodingTests.swift`, `PreviewFixtureDecodingTests.swift` and `Push/PushFixtureDecodingTests.swift`.

Widget scenarios use the prefix `scenario-widgets-` (`scenario-widgets-thread-list-busy.json`, `scenario-widgets-thread-list-empty.json`).

The attachment decode test is `AttachmentFixtureDecodingTests.swift`; it reads the server fixture `thread-attachments` and fails when that fixture is missing. `scenario-thread-attachments.json` is hand-written.
