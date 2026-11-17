# Where the changes plan and the code differ

These are the places the app deviates from the "changes" plan (Part B), and why.

- Task questions. The task screen answers Yak's questions with a native `TaskQuestionCard` over YakKit's `QuestionFlow`, not with `TaskQuestionBackend` and `QuestionCard`. The thread pane (2b) has no `QuestionCard` to reuse, and the native card keeps the draft through the same saved-draft rules.
- Tasks tile badge. The plan reads `counts.needsCoOwner` from the last cached task list. The tile counts the drawer list's `needsYou` rows that are tasks (`DrawerModel.needsYouTaskCount`), because the drawer already holds that list and it needs no second request.
- Stub page 2. `GET tasks?page=2` answers the last listed row again under a new id and description with `nextPage: null`, so a second page differs from the first and the append can be seen. The plan says "the last row again".
- Open PR follows by polling. After a 202, `FilesModel.followOpening` reads the thread show every 2 seconds, up to 15 times, while the state is `opening`, in addition to the engine's refresh on a `thread` event. The stub also publishes the `thread` event, so both paths are covered, but the app does not depend on the socket alone.
- `FilesModel` init. `FilesModel(thread:restoring:)` takes the thread model and an optional first file. The saved positions per thread live in `filesPosition`, read and written through `restorePositions(_:)` and `positionsData`, which `ChangedFilesView` keeps in `@SceneStorage("yak.files")`. A model cannot own scene storage itself, so the view carries it; `SceneState` is untouched.
