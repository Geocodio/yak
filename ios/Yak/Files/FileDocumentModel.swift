import Foundation
import Observation
import SwiftUI
import UIKit
import YakKit

/// One open file: its content, the colors, the person's edit and the save. It only wires the finished values of
/// `YakKit` to the views.
@MainActor @Observable
final class FileDocumentModel {
    let path: String
    let mark: FileMark?
    private(set) var snapshot: FileDocumentSnapshot
    /// The colored spans of `highlightedSource`, computed off the main actor.
    private(set) var tokens: [SyntaxToken] = []
    private(set) var highlightedSource: String?
    /// "Yak changed this file." for a few seconds after a quiet reload replaced the text.
    private(set) var showsChangedNotice = false
    /// The editor's text. The view writes it; every change goes to the document, which keeps the draft.
    private(set) var text = ""
    /// Long lines wrap instead of scrolling sideways.
    var wrapsLines = false

    @ObservationIgnored private let document: YakKit.FileDocument
    @ObservationIgnored private var reading: Task<Void, Never>?
    @ObservationIgnored private var highlighting: Task<Void, Never>?
    @ObservationIgnored private var noticeTask: Task<Void, Never>?
    @ObservationIgnored private var wasEditing = false

    /// Files over this many UTF-16 units are colored only for the first part.
    nonisolated static let colorLimit = 200_000

    init(document: YakKit.FileDocument, path: String, mark: FileMark?) {
        self.document = document
        self.path = path
        self.mark = mark
        snapshot = FileDocumentSnapshot(path: path)
    }

    // MARK: What the screen reads

    var content: FileContent? {
        switch snapshot.phase {
        case .text(let content), .binary(let content), .tooLarge(let content): content
        default: nil
        }
    }

    var viewedText: String? {
        if case .text(let content) = snapshot.phase { return content.content }
        return nil
    }

    var canEdit: Bool { snapshot.canEdit }
    var isEditing: Bool { snapshot.edit != .notEditing }
    var isDirty: Bool { snapshot.isDirty }
    var isSaving: Bool { snapshot.edit == .saving }
    var colorsStop: Bool { (viewedText?.utf16.count ?? 0) > Self.colorLimit }

    /// The marked file's diff lives in the Changed mode; the button shows only when that mode is built.
    static func canViewDiff(mark: FileMark?, built: [FilesMode] = FilesMode.built) -> Bool {
        (mark == .modified || mark == .added) && built.contains(.changed)
    }

    var canViewDiff: Bool { Self.canViewDiff(mark: mark) }

    /// A text over the save limit is refused before any request, so the editor shows it as it types.
    var isTooLargeToSave: Bool { text.utf8.count > FileEditDraft.maximumBytes }

    // MARK: Lifecycle

    func start() async {
        guard reading == nil else { return }
        let stream = await document.snapshots()
        reading = Task { [weak self] in
            for await next in stream {
                guard let self, !Task.isCancelled else { return }
                apply(next)
            }
        }
        await document.load()
    }

    func close() async {
        reading?.cancel()
        reading = nil
        highlighting?.cancel()
        noticeTask?.cancel()
        await document.close()
    }

    func reloadIfUnchanged() async { await document.reloadIfUnchanged() }

    func reload() async { await document.load() }

    private func apply(_ next: FileDocumentSnapshot) {
        let previousSource = highlightedSource
        snapshot = next
        let editing = next.edit != .notEditing
        if editing, !wasEditing || next.restoredDraft {
            text = next.text ?? ""
        }
        if !editing, wasEditing { text = "" }
        wasEditing = editing
        if case .text(let content) = next.phase, let source = content.content, source != previousSource {
            if previousSource != nil, !editing { announceChange() }
            highlight(source, language: content.language)
        }
    }

    private func highlight(_ source: String, language: FileLanguage) {
        highlightedSource = source
        highlighting?.cancel()
        highlighting = Task { [weak self] in
            let found = await Task.detached(priority: .userInitiated) {
                SyntaxTokens.tokenize(source, language: language, limit: FileDocumentModel.colorLimit)
            }.value
            guard let self, !Task.isCancelled, highlightedSource == source else { return }
            tokens = found
        }
    }

    /// The tokens arrive after `start`; a test waits for them here.
    func waitForHighlight() async {
        await highlighting?.value
    }

    private func announceChange() {
        showsChangedNotice = true
        noticeTask?.cancel()
        noticeTask = Task { [weak self] in
            do { try await Task.sleep(for: .seconds(4)) } catch { return }
            self?.showsChangedNotice = false
        }
    }

    // MARK: Editing

    @discardableResult
    func edit() async -> Bool { await document.beginEditing() }

    func setText(_ newText: String) {
        guard newText != text else { return }
        text = newText
        Task { await document.updateText(newText) }
    }

    func save() async {
        guard !isTooLargeToSave else { return }
        await document.save()
    }

    func keepMine() async { await document.keepMine() }
    func useTheirs() async { await document.useTheirs() }

    /// False when there are unsaved changes, so the view asks before it leaves.
    func cancel() async -> Bool {
        guard !snapshot.isDirty else { return false }
        await document.discardChanges()
        return true
    }

    func discard() async { await document.discardChanges() }

    func flushDraft() async { await document.flushDraft() }

    func scenePhaseChanged(_ phase: ScenePhase) async {
        if phase != .active { await document.flushDraft() }
    }

    func copyText() {
        UIPasteboard.general.string = text
    }

    func copyFile() { UIPasteboard.general.string = viewedText ?? "" }
}
