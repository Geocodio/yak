import Foundation
import Observation
import YakKit

/// The Files pane in Everything mode: the tree of the sandbox's files, find, the open file and the restore. It only
/// wires the finished values of `YakKit` to the views.
@MainActor @Observable
final class FilesEverythingModel {
    private(set) var tree: FileTreeSnapshot?
    /// What the person typed into Find; the tree searches after a short pause.
    private(set) var findText = ""
    private(set) var openPath: String?
    /// The open file. It lives as long as the model, so a mode or pane switch keeps its text.
    private(set) var document: FileDocumentModel?
    private(set) var isRestoring = false
    /// What a refused or failed restore said, shown under the Restore button.
    private(set) var restoreMessage: String?
    private(set) var isStarted = false

    @ObservationIgnored let thread: ThreadModel
    @ObservationIgnored private let restoreCoordinator: SandboxRestore
    @ObservationIgnored private let makeTree: () async -> FileTree?
    @ObservationIgnored private let makeDocument: (String) async -> YakKit.FileDocument?
    @ObservationIgnored private var fileTree: FileTree?
    @ObservationIgnored private var reading: Task<Void, Never>?
    @ObservationIgnored private var isOpening = false

    init(
        thread: ThreadModel, restore: SandboxRestore, makeTree: @escaping () async -> FileTree?,
        makeDocument: @escaping (String) async -> YakKit.FileDocument?
    ) {
        self.thread = thread
        restoreCoordinator = restore
        self.makeTree = makeTree
        self.makeDocument = makeDocument
    }

    convenience init(thread: ThreadModel, timings: FileTimings) {
        let session = thread.session
        let threadID = thread.threadID
        self.init(
            thread: thread, restore: thread.restore,
            makeTree: { await session.fileTree(thread: threadID, scope: .repo, timings: timings) },
            makeDocument: { path in
                await session.fileDocument(thread: threadID, scope: .repo, path: path, timings: timings)
            })
    }

    var canDrive: Bool { thread.snapshot?.show?.canDrive ?? false }
    var find: FindState { tree?.find ?? .idle }
    var isFinding: Bool { find != .idle }

    // MARK: Lifecycle

    /// Opens the root once.
    func start() async {
        guard !isStarted, let opened = await makeTree() else { return }
        guard !isStarted else { return }
        isStarted = true
        fileTree = opened
        let snapshots = await opened.snapshots()
        reading = Task { [weak self] in
            for await next in snapshots {
                guard let self, !Task.isCancelled else { return }
                tree = next
            }
        }
        await opened.start()
    }

    func stop() async {
        guard isStarted else { return }
        isStarted = false
        reading?.cancel()
        reading = nil
        let closing = document
        document = nil
        openPath = nil
        await closing?.close()
        await fileTree?.stop()
        fileTree = nil
    }

    // MARK: Tree

    func toggle(_ path: String) async { await fileTree?.toggle(path) }

    func retry() async {
        await fileTree?.retry()
        await document?.reloadIfUnchanged()
    }

    /// A finished turn may have changed files: the tree and the open file reload without a spinner.
    func turnFinished() async {
        await fileTree?.refreshQuietly()
        await document?.reloadIfUnchanged()
    }

    func networkChanged(isOnline: Bool) async { await fileTree?.networkChanged(isOnline: isOnline) }

    func setFindText(_ text: String) {
        guard text != findText else { return }
        findText = text
        let query = text
        Task { await fileTree?.search(query) }
    }

    func retryFind() {
        let query = findText
        Task { await fileTree?.search(query) }
    }

    /// The first search result, for Return in the find field.
    var firstResultPath: String? {
        if case .results(let results, _, _) = find { return results.first?.path }
        return nil
    }

    func mark(of path: String) -> FileMark? {
        if let row = tree?.rows.first(where: { $0.id == path }) { return row.mark }
        if case .results(let results, _, _) = find, let result = results.first(where: { $0.path == path }) {
            return result.mark
        }
        if tree?.addedPaths.contains(path) == true { return .added }
        if tree?.changedPaths.contains(path) == true { return .modified }
        return nil
    }

    // MARK: Open file

    /// Opens a file in the viewer, or in the editor with `edit`. Opening the file that is already open keeps it.
    func open(path: String, edit: Bool) async {
        guard !isOpening else { return }
        isOpening = true
        defer { isOpening = false }
        if openPath != path {
            guard let opened = await makeDocument(path) else { return }
            let previous = document
            let model = FileDocumentModel(document: opened, path: path, mark: mark(of: path))
            document = model
            openPath = path
            await previous?.close()
            await model.start()
        }
        if edit { _ = await document?.edit() }
    }

    func closeFile() async {
        let closing = document
        document = nil
        openPath = nil
        await closing?.close()
    }

    // MARK: Restore

    /// Restore through the coalescing request the Terminal pane shares, then ask the tree again.
    func restore() async {
        guard !isRestoring else { return }
        isRestoring = true
        restoreMessage = nil
        let outcome = await restoreCoordinator.run()
        isRestoring = false
        switch outcome {
        case .started: await fileTree?.retry()
        case .refused(let message): restoreMessage = message
        case .failed(let problem): restoreMessage = TerminalPaneModel.message(for: problem)
        }
    }
}
