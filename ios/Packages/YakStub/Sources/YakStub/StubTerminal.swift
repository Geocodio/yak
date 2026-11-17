import Foundation

/// The terminal and Files part of the stub: the workspace answer table, the thread rows its scenarios add, the
/// fake bridge's listener and the `/__stub/terminal` control routes. The scenario names are the workspace variants.
final class StubTerminalFeature: StubFeature, @unchecked Sendable {
    static let shared = StubTerminalFeature()

    let name = "terminal"
    let scenarios = WorkspaceVariant.allCases.map(\.rawValue)
    let fixturePairs = [
        StubFixturePair(server: "thread-terminal-session", scenario: "scenario-terminal-session"),
        StubFixturePair(server: "thread-terminal-session-waiting", scenario: "scenario-terminal-waiting"),
        StubFixturePair(server: "thread-files", scenario: "scenario-file-tree-root"),
        StubFixturePair(server: "thread-file", scenario: "scenario-file-text"),
        StubFixturePair(server: "thread-file-binary", scenario: "scenario-file-binary"),
        StubFixturePair(server: "thread-file-saved", scenario: "scenario-file-saved"),
        StubFixturePair(server: "error-file-conflict", scenario: "scenario-file-conflict"),
        StubFixturePair(server: "error-sandbox-unavailable", scenario: "scenario-files-waking"),
    ]
    let scenarioDataNames = [
        "scenario-terminal-restoring", "scenario-file-tree-src", "scenario-file-too-large",
        "scenario-files-destroyed", "scenario-files-asleep", "scenario-terminal-frames",
    ]

    /// One thread of a variant's list: the facts the app reads from the thread row and the thread show.
    private struct Row: Sendable {
        let id: String
        let title: String
        var status = "open"
        var sandboxState = "running"
        var canDrive = true
        var ownerSide = "yak"
        var blockedReason: String?
    }

    private struct State {
        var workspace: StubWorkspace?
        var variant = WorkspaceVariant.workspace
        var needsReset = true
        var origin = ""
        var usesServerFixtures = false
        var frames = Data()
        var bridge: FakeBridge?
        var bridgePort = 0
    }

    private let state = Locked(State())

    private static let terminalRows = [
        Row(id: "term0rgn", title: "A thread that refuses the app"),
        Row(id: "termf0rb", title: "A thread you can read", canDrive: false),
        Row(id: "termc10s", title: "A closed thread", status: "closed"),
        Row(id: "termdev1", title: "A thread on a computer", ownerSide: "device"),
        Row(id: "termg0ne", title: "A thread whose sandbox was removed", sandboxState: "destroyed"),
        Row(id: "termn0sb", title: "A thread with no sandbox yet", sandboxState: "none"),
        Row(id: "termb1kd", title: "A thread whose restore failed", blockedReason: "restore_failed"),
        Row(id: "term4429", title: "A thread with too many shells"),
    ]
    private static let fileRows = [
        Row(id: "fwak1ng0", title: "Files while the sandbox wakes", sandboxState: "paused"),
        Row(id: "fs10t000", title: "Files waiting for a slot", sandboxState: "paused"),
        Row(id: "fas1eep0", title: "Files of a sleeping sandbox", sandboxState: "paused", canDrive: false),
        Row(id: "fg0ne000", title: "Files of a removed sandbox", sandboxState: "destroyed"),
        Row(id: "fr3st0re", title: "Files of a long restore", sandboxState: "destroyed"),
        Row(id: "fc10sed0", title: "Files of a closed thread", status: "closed", sandboxState: "destroyed"),
        Row(id: "fdev1ce0", title: "Files on a computer", ownerSide: "device"),
        Row(id: "fbr0ken0", title: "Files that fail"),
    ]

    private static func extraRows(_ variant: WorkspaceVariant) -> [Row] {
        switch variant {
        case .terminalStates, .terminalRestoringForever: terminalRows
        case .filesStates: fileRows
        default: []
        }
    }

    private static func variant(for scenario: String) -> WorkspaceVariant? {
        WorkspaceVariant(rawValue: scenario) ?? (scenario == Scenario.serverFixtures.rawValue ? .workspace : nil)
    }

    // MARK: Lifecycle

    func start() async throws {
        let bridge = FakeBridge(
            frames: { [state] in state.get().frames }, origin: { [state] in state.get().origin },
            admit: { [state] token in state.get().workspace?.admit(token: token) ?? .refuse })
        let port = try await bridge.start()
        state.mutate {
            $0.bridge = bridge
            $0.bridgePort = port
        }
    }

    func reset() {
        let bridge = state.mutate { current -> FakeBridge? in
            current.needsReset = true
            return current.bridge
        }
        bridge?.resetCounts()
    }

    func setOnline(_ isOnline: Bool) {
        if !isOnline { state.get().bridge?.closeAll(code: 1001) }
    }

    var putCount: Int { state.get().workspace?.putCount ?? 0 }

    // MARK: Control routes

    func control(_ request: StubRequest) -> StubReply? {
        let bridge = state.get().bridge
        switch (request.method, request.path) {
        case ("GET", "/__stub/terminal/opened"):
            return text(String(bridge?.openedCount ?? 0))
        case ("GET", "/__stub/terminal/last-resize"):
            let size = bridge?.lastResize
            let object: [String: Any] = size.map { ["cols": $0.columns, "rows": $0.rows] } ?? [:]
            return StubReply(
                answer: .reply(
                    status: 200, contentType: "application/json",
                    body: (try? JSONSerialization.data(withJSONObject: object)) ?? Data("{}".utf8)))
        case ("POST", "/__stub/terminal/close"):
            let body = String(decoding: request.body, as: UTF8.self).trimmingCharacters(in: .whitespacesAndNewlines)
            let object = (try? JSONSerialization.jsonObject(with: request.body)) as? [String: Any]
            let code = Int(body) ?? object?["code"] as? Int
            bridge?.closeAll(code: code ?? 1001)
            return text("closed")
        default:
            return nil
        }
    }

    private func text(_ value: String) -> StubReply {
        StubReply(answer: .reply(status: 200, contentType: "text/plain", body: Data(value.utf8)))
    }

    // MARK: Routes

    func answer(route: String, request: StubRequest, context: StubContext) -> StubReply? {
        guard let variant = Self.variant(for: context.scenario) else { return nil }
        let workspace = prepare(variant: variant, context: context)
        let parts = request.path.dropFirst(Scenario.apiPrefix.count).split(separator: "/").map(String.init)
        guard parts.first == "threads" else { return nil }
        let rows = Self.extraRows(variant)
        if parts.count == 1, request.method == "GET" { return list(rows: rows, context: context) }
        guard parts.count >= 2 else { return nil }
        let id = parts[1]
        let tail = parts.dropFirst(2).joined(separator: "/")
        if tail == "restore/retry", request.method == "POST" {
            workspace.restoreAsked(thread: id)
            return nil
        }
        let row = rows.first { $0.id == id }
        let canDrive = row?.canDrive ?? !(variant == .filesReadOnly && id == "d0e515ef")
        let workspaceRequest = WorkspaceRequest(
            method: request.method, path: parts.joined(separator: "/"), query: request.query,
            headers: request.headers, body: request.body)
        if let answer = workspace.answer(workspaceRequest, canDrive: canDrive) {
            if answer.dropsConnection { return StubReply(answer: .drop) }
            return StubReply(
                answer: .reply(status: answer.status, contentType: "application/json", body: answer.body),
                delay: answer.delay)
        }
        if let row {
            switch (request.method, tail) {
            case ("GET", ""): return json(show(row, context: context))
            case ("GET", "events"):
                return json(["events": [[String: Any]](), "latestSequence": 0, "source": "database"])
            case ("POST", "seen"):
                return StubReply(answer: .reply(status: 204, contentType: "application/json", body: Data()))
            default: return nil
            }
        }
        if id == "d0e515ef", request.method == "GET", tail.isEmpty { return coreShow(variant, context: context) }
        return nil
    }

    private func prepare(variant: WorkspaceVariant, context: StubContext) -> StubWorkspace {
        let current = state.get()
        if !current.needsReset, current.origin == context.baseURL, let workspace = current.workspace {
            return workspace
        }
        let fixtures = context.fixtures
        let isServerFirst = context.scenario == Scenario.serverFixtures.rawValue
        let load: @Sendable (String) -> Data? = { name in
            if isServerFirst {
                let object = fixtures.serverFirst(name)
                return object.isEmpty ? nil : try? JSONSerialization.data(withJSONObject: object)
            }
            return fixtures.scenarioFiles[name]
        }
        let workspace = StubWorkspace(load: load, ownOrigin: context.baseURL)
        workspace.reset(variant, bridgePort: current.bridgePort)
        state.mutate { stored in
            stored.origin = context.baseURL
            stored.frames = fixtures.scenarioFiles["scenario-terminal-frames"] ?? Data()
            stored.workspace = workspace
            stored.variant = variant
            stored.needsReset = false
        }
        return workspace
    }

    // MARK: Thread rows

    private func list(rows: [Row], context: StubContext) -> StubReply? {
        guard !rows.isEmpty else { return nil }
        guard case .reply(let status, _, let body) = context.core(), status == 200,
            var list = (try? JSONSerialization.jsonObject(with: body)) as? [String: Any]
        else { return nil }
        var threads = list["threads"] as? [[String: Any]] ?? []
        let template: [String: Any] =
            threads.first.map { row in
                var copy = row
                copy["children"] = [[String: Any]]()
                return copy
            } ?? [:]
        var added: [[String: Any]] = []
        for row in rows {
            var entry = template
            entry["id"] = row.id
            entry["title"] = row.title
            entry["status"] = row.status == "closed" ? "closed" : "stopped"
            added.append(entry)
        }
        // The state rows lead the list, so every one is in reach without scrolling.
        threads.insert(contentsOf: added, at: 0)
        list["threads"] = threads
        list["hasAnyThreads"] = true
        return json(list)
    }

    private func show(_ row: Row, context: StubContext) -> [String: Any] {
        var show = context.fixtures.scenario("scenario-thread-show", baseURL: context.baseURL)
        var thread = show["thread"] as? [String: Any] ?? [:]
        thread["id"] = row.id
        thread["title"] = row.title
        thread["status"] = row.status
        thread["sandboxState"] = row.sandboxState
        thread["ownerSide"] = row.ownerSide
        thread["blockedReason"] = row.blockedReason ?? NSNull()
        show["thread"] = thread
        show["canDrive"] = row.canDrive
        show["question"] = NSNull()
        show["messages"] = [[String: Any]]()
        show["latestSequence"] = 0
        var composer = show["composer"] as? [String: Any] ?? [:]
        if row.status == "closed" {
            composer["state"] = "disabled_closed"
        } else if !row.canDrive {
            composer["state"] = "readonly"
        } else if row.ownerSide == "device" {
            composer["state"] = "disabled_computer"
        }
        show["composer"] = composer
        return show
    }

    /// The core show of `d0e515ef` with the facts of the variant that differ from `paired`.
    private func coreShow(_ variant: WorkspaceVariant, context: StubContext) -> StubReply? {
        let sandbox: String?
        switch variant {
        case .terminalWaking: sandbox = "paused"
        case .terminalSlowWake: sandbox = "stopped"
        default: sandbox = nil
        }
        guard sandbox != nil || variant == .filesReadOnly else { return nil }
        guard case .reply(let status, _, let body) = context.core(), status == 200,
            var show = (try? JSONSerialization.jsonObject(with: body)) as? [String: Any]
        else { return nil }
        if let sandbox {
            var thread = show["thread"] as? [String: Any] ?? [:]
            thread["sandboxState"] = sandbox
            show["thread"] = thread
        }
        if variant == .filesReadOnly {
            show["canDrive"] = false
            var composer = show["composer"] as? [String: Any] ?? [:]
            composer["state"] = "readonly"
            show["composer"] = composer
        }
        return json(show)
    }

    private func json(_ object: Any) -> StubReply {
        let data = (try? JSONSerialization.data(withJSONObject: object)) ?? Data("{}".utf8)
        return StubReply(answer: .reply(status: 200, contentType: "application/json", body: data))
    }
}

/// `/__stub/files/puts`: how many saves reached the stub since the scenario started.
final class StubFilesControl: StubFeature, @unchecked Sendable {
    let name = "files"

    func control(_ request: StubRequest) -> StubReply? {
        guard request.method == "GET", request.path == "/__stub/files/puts" else { return nil }
        let body = Data(String(StubTerminalFeature.shared.putCount).utf8)
        return StubReply(answer: .reply(status: 200, contentType: "text/plain", body: body))
    }
}
