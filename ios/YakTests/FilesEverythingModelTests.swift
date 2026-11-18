import Testing
import YakKit

@testable import Yak

@MainActor @Test func startsOnceAndOpensTheRoot() async throws {
    let fixture = try await FilesFixture()
    await fixture.model.start()
    await fixture.model.start()
    #expect(await fixture.eventually { fixture.model.tree?.rows.count == 2 })
    #expect(fixture.model.tree?.rows.map(\.name) == ["src", "README.md"])
    #expect(fixture.transport.count("GET /api/app/v1/threads/d0e515ef/files?") == 1)
    await fixture.finish()
}

@MainActor @Test func aFinishedTurnRefreshesQuietly() async throws {
    let fixture = try await FilesFixture()
    await fixture.model.start()
    #expect(await fixture.eventually { fixture.model.tree?.rows.count == 2 })
    await fixture.model.turnFinished()
    #expect(fixture.transport.count("GET /api/app/v1/threads/d0e515ef/files?") == 2)
    #expect(fixture.model.tree?.root == .loaded(truncated: false))
    #expect(fixture.model.tree?.rows.count == 2)
    await fixture.finish()
}

@MainActor @Test func findFiltersAsTheTextChanges() async throws {
    let fixture = try await FilesFixture()
    await fixture.model.start()
    #expect(await fixture.eventually { fixture.model.tree?.rows.count == 2 })
    fixture.model.setFindText("import")
    #expect(
        await fixture.eventually {
            if case .results(let results, _, _) = fixture.model.find {
                results.first?.path == "src/Importer.php"
            } else {
                false
            }
        })
    #expect(fixture.model.firstResultPath == "src/Importer.php")
    fixture.model.setFindText("")
    #expect(await fixture.eventually { fixture.model.find == .idle })
    await fixture.finish()
}

@MainActor @Test func restoreIsCoalesced() async throws {
    let fixture = try await FilesFixture()
    let second = FilesEverythingModel(thread: fixture.thread, timings: .standard)
    async let first: Void = fixture.model.restore()
    async let other: Void = second.restore()
    _ = await (first, other)
    #expect(fixture.transport.count("POST /api/app/v1/threads/d0e515ef/restore/retry") == 1)
    await fixture.finish()
}

@MainActor @Test func theOpenFileSurvivesAModeSwitch() async throws {
    let fixture = try await FilesFixture()
    await fixture.model.start()
    await fixture.model.open(path: "src/Importer.php", edit: false)
    let document = try #require(fixture.model.document)
    #expect(fixture.model.openPath == "src/Importer.php")
    // Switching modes only hides the view; the model and its document stay.
    #expect(fixture.model.document === document)
    #expect(fixture.model.mark(of: "src/Importer.php") == .modified)
    await fixture.finish()
}

@Test func initialModeFollowsChanges() {
    #expect(FilesMode.initial(hasChanges: true, built: [.changed, .everything]) == .changed)
    #expect(FilesMode.initial(hasChanges: true, built: [.everything]) == .everything)
    #expect(FilesMode.initial(hasChanges: false, built: [.changed, .everything]) == .everything)
}

@MainActor @Test func editedTextSurvivesAModeAndPaneSwitch() async throws {
    let fixture = try await FilesFixture()
    await fixture.model.start()
    await fixture.model.open(path: "src/Importer.php", edit: true)
    let document = try #require(fixture.model.document)
    #expect(await fixture.eventually { document.isEditing })
    let before = fixture.transport.count("GET /api/app/v1/threads/d0e515ef/files/content")
    document.setText("typed line\n")
    #expect(await fixture.eventually { document.isDirty })
    await document.flushDraft()
    // The pane host and the mode switch only hide the view.
    #expect(fixture.model.document === document)
    #expect(document.text == "typed line\n")
    #expect(document.isDirty)
    #expect(fixture.transport.count("PUT") == 0)
    #expect(fixture.transport.count("GET /api/app/v1/threads/d0e515ef/files/content") == before)
    await fixture.finish()
}

@MainActor @Test func anOfflineHandOffShowsTheFileOnceTheConnectionReturns() async throws {
    let fixture = try await FilesFixture()
    fixture.transport.setContentOffline(true)
    #expect(await fixture.model.open(path: "src/Importer.php", edit: true))
    let document = try #require(fixture.model.document)
    #expect(await fixture.eventually { document.hasFailedToLoad })
    // Asking again while still offline keeps the failed document and does not lose the wish to edit.
    #expect(await fixture.model.open(path: "src/Importer.php", edit: true))
    fixture.transport.setContentOffline(false)
    await fixture.model.networkChanged(isOnline: true)
    #expect(await fixture.eventually { document.viewedText != nil && document.isEditing })
    await fixture.finish()
}

@MainActor @Test func aHandOffDuringAnOpenWaitsForIt() async throws {
    let fixture = try await FilesFixture()
    async let first = fixture.model.open(path: "src/Importer.php", edit: false)
    async let second = fixture.model.open(path: "README.md", edit: false)
    let results = await (first, second)
    #expect(results.0 && results.1)
    #expect(fixture.model.openPath == "README.md")
    await fixture.finish()
}

@MainActor @Test func aHandOffToAnotherFileKeepsTheDraftOfTheFirst() async throws {
    let fixture = try await FilesFixture()
    await fixture.model.open(path: "src/Importer.php", edit: true)
    let document = try #require(fixture.model.document)
    #expect(await fixture.eventually { document.isEditing })
    document.setText("typed line\n")
    #expect(await fixture.eventually { document.isDirty })
    await fixture.model.open(path: "README.md", edit: true)
    #expect(fixture.model.openPath == "README.md")
    #expect(fixture.transport.count("PUT") == 0)
    await fixture.model.open(path: "src/Importer.php", edit: false)
    let reopened = try #require(fixture.model.document)
    #expect(await fixture.eventually { reopened.isEditing && reopened.text == "typed line\n" })
    await fixture.finish()
}

@MainActor @Test func aHandOffToTheSameFileLeavesTheEditorAlone() async throws {
    let fixture = try await FilesFixture()
    await fixture.model.open(path: "src/Importer.php", edit: true)
    let document = try #require(fixture.model.document)
    #expect(await fixture.eventually { document.isEditing })
    document.setText("typed line\n")
    #expect(await fixture.eventually { document.isDirty })
    let before = fixture.transport.count("GET /api/app/v1/threads/d0e515ef/files/content")
    await fixture.model.open(path: "src/Importer.php", edit: true)
    #expect(fixture.model.document === document)
    #expect(document.text == "typed line\n" && document.isEditing)
    #expect(fixture.transport.count("GET /api/app/v1/threads/d0e515ef/files/content") == before)
    await fixture.finish()
}

@Test func treeDisplayPutsNotesUnderTheirFolder() {
    func row(_ path: String, depth: Int, directory: Bool, state: FolderState? = nil, empty: Bool = false) -> FileTreeRow
    {
        FileTreeRow(
            id: path, name: path, depth: depth, isDirectory: directory, isExpanded: directory, folderState: state,
            isEmptyFolder: empty, mark: nil)
    }
    let rows = [
        row("a", depth: 0, directory: true, state: .loaded(truncated: true)),
        row("a/x", depth: 1, directory: false),
        row("b", depth: 0, directory: true, state: .loaded(truncated: false), empty: true),
        row("c", depth: 0, directory: true, state: .loading),
    ]
    let ids = FileTreeDisplay.make(rows: rows, rootState: .loaded(truncated: false), isOffline: false).map(\.id)
    #expect(ids == ["a", "a/x", "truncated:a", "b", "empty:b", "c", "loading:c"])
}
