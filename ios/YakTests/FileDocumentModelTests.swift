import Foundation
import Testing
import YakKit

@testable import Yak

@MainActor private func openedDocument(_ fixture: FilesFixture, edit: Bool = false) async throws -> FileDocumentModel {
    await fixture.model.start()
    await fixture.model.open(path: "src/Importer.php", edit: edit)
    let document = try #require(fixture.model.document)
    #expect(await fixture.eventually { document.viewedText != nil || document.isEditing })
    return document
}

@MainActor @Test func highlightingRunsOffTheMainActor() async throws {
    let fixture = try await FilesFixture()
    fixture.transport.setContent("<?php\nreturn 42; // done\n")
    let document = try await openedDocument(fixture)
    await document.waitForHighlight()
    #expect(!document.tokens.isEmpty)
    #expect(document.highlightedSource == document.viewedText)
    await fixture.finish()
}

@MainActor @Test func viewDiffOnlyForMarkedFilesWithChangedBuilt() {
    #expect(FileDocumentModel.canViewDiff(mark: .modified, built: [.changed, .everything]))
    #expect(FileDocumentModel.canViewDiff(mark: .added, built: [.changed, .everything]))
    #expect(!FileDocumentModel.canViewDiff(mark: nil, built: [.changed, .everything]))
    #expect(!FileDocumentModel.canViewDiff(mark: .containsChanges, built: [.changed, .everything]))
    #expect(!FileDocumentModel.canViewDiff(mark: .modified, built: [.everything]))
}

@MainActor @Test func editIsHiddenForAReader() async throws {
    let fixture = try await FilesFixture()
    fixture.transport.canEdit = false
    let document = try await openedDocument(fixture)
    #expect(!document.canEdit)
    #expect(await document.edit() == false)
    await fixture.finish()
}

@MainActor @Test func cancelAsksOnlyWithChanges() async throws {
    let fixture = try await FilesFixture()
    let document = try await openedDocument(fixture, edit: true)
    #expect(await document.cancel())
    #expect(await fixture.eventually { !document.isEditing })
    #expect(await document.edit())
    document.setText("changed\n")
    #expect(await fixture.eventually { document.isDirty })
    #expect(await document.cancel() == false)
    #expect(document.isEditing)
    await fixture.finish()
}

@MainActor @Test func backgroundFlushesTheDraft() async throws {
    let fixture = try await FilesFixture()
    let document = try await openedDocument(fixture, edit: true)
    document.setText("draft\n")
    #expect(await fixture.eventually { document.isDirty })
    await document.scenePhaseChanged(.background)
    let cache = try #require(await fixture.session.instanceCache())
    let draft = await cache.fileEditDraft(thread: "d0e515ef", scope: .repo, path: "src/Importer.php")
    #expect(draft != nil)
    await fixture.finish()
}

/// The editor's hidden Command S button runs `document.save()`; this is that action on the shortcut the button carries.
@MainActor @Test func commandSSaves() async throws {
    #expect(AppShortcut.save.key == .character("s"))
    #expect(AppShortcut.save.modifiers == [.command])
    let fixture = try await FilesFixture()
    let document = try await openedDocument(fixture, edit: true)
    document.setText("typed\n")
    #expect(await fixture.eventually { document.isDirty })
    await document.save()
    #expect(await fixture.eventually { if case .saved = document.snapshot.edit { true } else { false } })
    await fixture.finish()
}

@MainActor @Test func aSaveShowsTheSavedLineAndAConflictOpensTheCompare() async throws {
    let fixture = try await FilesFixture()
    let document = try await openedDocument(fixture, edit: true)
    document.setText("mine\n")
    #expect(await fixture.eventually { document.isDirty })
    fixture.transport.setSaveAnswer(.conflict)
    await document.save()
    #expect(await fixture.eventually { if case .conflict = document.snapshot.edit { true } else { false } })
    fixture.transport.setSaveAnswer(.saved)
    await document.keepMine()
    #expect(await fixture.eventually { if case .saved = document.snapshot.edit { true } else { false } })
    await fixture.finish()
}

@Test func changedLinesAreTheOnesTheOtherTextLacks() {
    #expect(ChangedLines.indexes(in: "a\nb\nc", comparedWith: "a\nx\nc") == [1])
    #expect(ChangedLines.indexes(in: "same", comparedWith: "same").isEmpty)
}
