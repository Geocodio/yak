import Foundation
import Observation
import SwiftUI
import UIKit
import YakKit

/// The Terminal pane's state: the shells of one thread, each a connection and a surface, the control latch and the
/// restore. It only wires the finished values of `YakKit` to the views.
@MainActor @Observable
final class TerminalPaneModel {
    /// One open shell. The surface lives as long as the shell, so switching shells never loses a scrollback.
    struct Shell {
        let connection: TerminalConnection
        let surface: any TerminalSurface
    }

    private(set) var shells = ShellList()
    private(set) var snapshots: [UUID: TerminalSnapshot] = [:]
    private(set) var isRestoring = false
    /// What a refused or failed restore said, shown under the Restore button.
    private(set) var restoreMessage: String?
    private(set) var isStarted = false
    /// Shells that have shown output at least once; the full-pane states only cover a shell that never connected.
    private(set) var connectedOnce: Set<UUID> = []
    /// Shells the person has scrolled away from the end of.
    private(set) var scrolledUp: Set<UUID> = []
    private(set) var isKeyboardShown = false
    /// The person tapped the output, so a hardware keyboard types into it.
    var isSurfaceFocused = false
    var control = ControlLatch()
    /// The list of shells opened from the banner when there are too many.
    var isShowingShells = false
    /// The connected shell waiting for the person to confirm closing it; the shell menu and Command W share it.
    var closingShell: ShellEntry?
    private(set) var isOpening = false

    @ObservationIgnored private(set) var open: [UUID: Shell] = [:]
    @ObservationIgnored private let thread: ThreadModel
    @ObservationIgnored private let restoreCoordinator: SandboxRestore
    @ObservationIgnored private let makeConnection: (String) async -> TerminalConnection?
    @ObservationIgnored private let makeSurface: () -> any TerminalSurface
    @ObservationIgnored private var tasks: [UUID: [Task<Void, Never>]] = [:]
    @ObservationIgnored private var pendingOutput: [UUID: Data] = [:]
    @ObservationIgnored private var flushTask: Task<Void, Never>?
    @ObservationIgnored private var resizeTask: Task<Void, Never>?
    @ObservationIgnored private var size = (columns: 80, rows: 24)
    @ObservationIgnored private var factsKey = ""
    @ObservationIgnored private var backgroundTask = UIBackgroundTaskIdentifier.invalid
    @ObservationIgnored private var isOnline = true

    init(
        thread: ThreadModel, restore: SandboxRestore, makeConnection: @escaping (String) async -> TerminalConnection?,
        makeSurface: @escaping () -> any TerminalSurface = { SwiftTermSurface() }
    ) {
        self.thread = thread
        restoreCoordinator = restore
        self.makeConnection = makeConnection
        self.makeSurface = makeSurface
    }

    convenience init(thread: ThreadModel, timings: TerminalTimings, connector: any TerminalSocketConnector) {
        let session = thread.session
        let threadID = thread.threadID
        self.init(
            thread: thread, restore: thread.restore,
            makeConnection: { sandboxState in
                await session.terminalConnection(
                    thread: threadID, sandboxState: sandboxState, connector: connector, timings: timings)
            })
    }

    // MARK: What the screen reads

    private var facts: ThreadDetail? { thread.snapshot?.show?.thread }
    var hasFacts: Bool { facts != nil }
    var canDrive: Bool { thread.snapshot?.show?.canDrive ?? false }
    var sandboxState: String { facts?.sandboxState ?? "running" }
    var personAwakeCap: Int? { facts?.preview?.personAwakeCap }
    var focusedID: UUID? { shells.focused }
    var focusedShell: Shell? { shells.focused.flatMap { open[$0] } }

    /// A reader never asks for a shell, so the pane says why without a request.
    var focusedSnapshot: TerminalSnapshot? {
        if hasFacts, !canDrive {
            return TerminalSnapshot(phase: .refused(.forbidden), droppedKeys: false, columns: 80, rows: 24)
        }
        return shells.focused.flatMap { snapshots[$0] }
    }

    var hasConnectedOnce: Bool { shells.focused.map { connectedOnce.contains($0) } ?? false }
    var isScrolledUp: Bool { shells.focused.map { scrolledUp.contains($0) } ?? false }

    // MARK: Lifecycle

    /// Opens "main" once, when the facts of the thread are known.
    func start() async {
        guard !isStarted, hasFacts else { return }
        isStarted = true
        factsKey = Self.key(of: facts)
        if canDrive { await openShell() }
    }

    func stop() async {
        isStarted = false
        resizeTask?.cancel()
        flushTask?.cancel()
        flushTask = nil
        endBackgroundTask()
        for id in Array(open.keys) { await close(id) }
    }

    /// A new shell, named and focused. Nothing happens at the maximum or while another is still opening.
    func newShell() async {
        guard canDrive, shells.canAdd, !isOpening, !isOpeningAShell else { return }
        await openShell()
    }

    func closeShell(_ id: UUID) async { await close(id) }

    /// A shell that is not connected closes without asking; a connected one stops what runs in it, so it asks first.
    func requestClose(_ entry: ShellEntry) {
        if snapshots[entry.id]?.phase == .connected {
            closingShell = entry
        } else {
            Task { await close(entry.id) }
        }
    }

    func focus(_ id: UUID) {
        isSurfaceFocused = false
        shells.focus(id)
        focusedShell?.surface.scrollToEnd()
    }

    func retry() async { await focusedShell?.connection.reconnectNow() }

    /// Restore through the coalescing request. Once it is accepted every shell asks again.
    func restore() async {
        guard !isRestoring else { return }
        isRestoring = true
        restoreMessage = nil
        let outcome = await restoreCoordinator.run()
        isRestoring = false
        switch outcome {
        case .started:
            for shell in open.values { await shell.connection.restoreStarted() }
        case .refused(let message): restoreMessage = message
        case .failed(let problem): restoreMessage = Self.message(for: problem)
        }
    }

    /// A closed thread or a new block reason closes the shells and asks once more, so the pane lands in the
    /// matching state.
    func threadFactsChanged() async {
        guard isStarted, hasFacts else { return }
        let next = Self.key(of: facts)
        guard next != factsKey else { return }
        factsKey = next
        for id in Array(open.keys) { await close(id) }
        if canDrive { await openShell() }
    }

    func scenePhaseChanged(_ phase: ScenePhase) async {
        switch phase {
        case .background:
            guard backgroundTask == .invalid, !open.isEmpty else { return }
            backgroundTask = UIApplication.shared.beginBackgroundTask(withName: "terminal") { [weak self] in
                MainActor.assumeIsolated { self?.backgroundTimeExpired() }
            }
        case .active:
            endBackgroundTask()
            for shell in open.values { await shell.connection.resume() }
        default: break
        }
    }

    func networkChanged(isOnline: Bool) async {
        self.isOnline = isOnline
        for shell in open.values { await shell.connection.networkChanged(isOnline: isOnline) }
    }

    /// Runs inside the expiration handler: each suspend starts without being awaited and the background task ends
    /// before the handler returns, as UIKit requires.
    func backgroundTimeExpired() {
        for shell in open.values {
            let connection = shell.connection
            Task { await connection.suspend() }
        }
        endBackgroundTask()
    }

    private func endBackgroundTask() {
        guard backgroundTask != .invalid else { return }
        UIApplication.shared.endBackgroundTask(backgroundTask)
        backgroundTask = .invalid
    }

    // MARK: Keys

    /// A key of the key row: Control applies to this key only.
    func press(_ key: TerminalKey) {
        let bytes = TerminalKeys.bytes(
            for: key, control: control.consume(),
            applicationCursor: focusedShell?.surface.isApplicationCursorMode ?? false)
        send(bytes)
    }

    /// Keys typed into the surface. Control turns a single letter into its control byte.
    func typed(_ bytes: [UInt8]) {
        send(bytesForTyped(bytes))
    }

    func bytesForTyped(_ bytes: [UInt8]) -> [UInt8] {
        guard control.consume() else { return bytes }
        guard bytes.count == 1 else { return bytes }
        let scalar = UnicodeScalar(bytes[0])
        return TerminalKeys.bytes(for: .character(Character(scalar)), control: true, applicationCursor: false)
    }

    private func send(_ bytes: [UInt8]) {
        guard let connection = focusedShell?.connection, !bytes.isEmpty else { return }
        Task { await connection.send(bytes) }
    }

    func toggleKeyboard() {
        guard let surface = focusedShell?.surface else { return }
        if isKeyboardShown { surface.resignFocus() } else { surface.focus() }
    }

    func keyboardChanged(isShown: Bool) { isKeyboardShown = isShown }

    func jumpToEnd() {
        focusedShell?.surface.scrollToEnd()
        if let id = shells.focused { scrolledUp.remove(id) }
    }

    /// The surface's size, sent to every shell at most once per 100 ms.
    func sizeChanged(columns: Int, rows: Int) {
        guard columns > 0, rows > 0, (columns, rows) != (size.columns, size.rows) else { return }
        size = (columns, rows)
        resizeTask?.cancel()
        resizeTask = Task { [weak self] in
            do { try await Task.sleep(for: .milliseconds(100)) } catch { return }
            guard let self else { return }
            for shell in open.values { await shell.connection.resize(columns: size.columns, rows: size.rows) }
        }
    }

    // MARK: Shells

    private var isOpeningAShell: Bool {
        shells.shells.contains { entry in
            switch snapshots[entry.id]?.phase {
            case .idle, .opening, .waitingForSlot, .restoring, nil: true
            default: false
            }
        }
    }

    private func openShell() async {
        isOpening = true
        defer { isOpening = false }
        guard let facts, let connection = await makeConnection(facts.sandboxState) else { return }
        // The model stopped while the connection was being made, so nothing may start it.
        guard isStarted else {
            await connection.close()
            return
        }
        let id = UUID()
        guard shells.add(id: id, hasPreviewPort: facts.preview?.port != nil) != nil else {
            await connection.close()
            return
        }
        let surface = makeSurface()
        surface.onInput = { [weak self] bytes in self?.typed(bytes) }
        surface.onSizeChange = { [weak self] columns, rows in self?.sizeChanged(columns: columns, rows: rows) }
        surface.onScrollPositionChange = { [weak self] isAtEnd in
            guard let self else { return }
            if isAtEnd { scrolledUp.remove(id) } else { scrolledUp.insert(id) }
        }
        open[id] = Shell(connection: connection, surface: surface)
        snapshots[id] = TerminalSnapshot(phase: .idle, droppedKeys: false, columns: size.columns, rows: size.rows)
        let snapshotStream = await connection.snapshots()
        let outputStream = await connection.output()
        let reading = Task { [weak self] in
            for await next in snapshotStream {
                guard let self, !Task.isCancelled else { return }
                snapshots[id] = next
                if next.phase == .connected { connectedOnce.insert(id) }
            }
        }
        let writing = Task { [weak self] in
            for await output in outputStream {
                guard let self, !Task.isCancelled else { return }
                receive(output, from: id)
            }
        }
        tasks[id] = [reading, writing]
        if !isOnline { await connection.networkChanged(isOnline: false) }
        await connection.start(columns: size.columns, rows: size.rows)
    }

    private func close(_ id: UUID) async {
        for task in tasks[id] ?? [] { task.cancel() }
        tasks[id] = nil
        pendingOutput[id] = nil
        let shell = open.removeValue(forKey: id)
        shells.remove(id)
        snapshots[id] = nil
        connectedOnce.remove(id)
        scrolledUp.remove(id)
        await shell?.connection.close()
    }

    // MARK: Output

    /// Bytes are gathered and handed to the surface at most once per display frame.
    func receive(_ output: TerminalOutput, from id: UUID) {
        switch output {
        case .bytes(let data):
            pendingOutput[id, default: Data()].append(data)
            scheduleFlush()
        case .newShell(let droppedAt):
            flush()
            let time = droppedAt.formatted(date: .omitted, time: .shortened)
            open[id]?.surface.feedDivider(String(localized: "terminal.divider \(time)"))
        }
    }

    private func scheduleFlush() {
        guard flushTask == nil else { return }
        flushTask = Task { [weak self] in
            do { try await Task.sleep(for: .milliseconds(16)) } catch {
                self?.flushTask = nil
                return
            }
            self?.flush()
        }
    }

    private func flush() {
        flushTask = nil
        for (id, data) in pendingOutput {
            guard let shell = open[id] else { continue }
            let wasAtEnd = !scrolledUp.contains(id)
            shell.surface.feed(data)
            if wasAtEnd { shell.surface.scrollToEnd() }
        }
        pendingOutput = [:]
    }

    // MARK: Helpers

    private static func key(of facts: ThreadDetail?) -> String {
        guard let facts else { return "" }
        return "\(facts.status)|\(facts.blockedReason ?? "")"
    }

    static func message(for problem: ThreadProblem) -> String {
        switch problem {
        case .server(_, let message): message
        default: String(localized: "terminal.restore.failed")
        }
    }
}
