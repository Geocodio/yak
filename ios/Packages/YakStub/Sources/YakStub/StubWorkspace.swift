import Foundation

/// The workspace scenarios. Each raw value is the scenario's name.
enum WorkspaceVariant: String, CaseIterable, Sendable {
    case workspace
    case terminalWaking = "terminal-waking"
    case terminalSlowWake = "terminal-slow-wake"
    case terminalWaiting = "terminal-waiting"
    case terminalWaitingForever = "terminal-waiting-forever"
    case terminalStates = "terminal-states"
    case terminalRestoringForever = "terminal-restoring-forever"
    case filesStates = "files-states"
    case filesConflict = "files-conflict"
    case filesLostSave = "files-lost-save"
    case filesReadOnly = "files-read-only"
    case filesSaveClosed = "files-save-closed"
    case filesSaveAsleep = "files-save-asleep"
    /// The file tree answers after 14 seconds.
    case filesSlow = "files-slow"
    /// A file's text answers after 14 seconds.
    case fileSlowRead = "file-slow-read"
    /// A save answers after 14 seconds.
    case fileSlowSave = "file-slow-save"
    /// A save finds that the file was deleted meanwhile.
    case filesConflictDeleted = "files-conflict-deleted"
}

struct WorkspaceRequest: Sendable {
    let method: String
    /// After `/api/app/v1/`, for example `threads/d0e515ef/files`.
    let path: String
    let query: [String: String]
    /// Names compared case-insensitively.
    let headers: [String: String]
    let body: Data?

    func header(_ name: String) -> String? {
        headers.first { $0.key.lowercased() == name.lowercased() }?.value
    }
}

struct WorkspaceAnswer: Equatable, Sendable {
    let status: Int
    let body: Data
    /// The server waits this long before answering.
    let delay: Duration
    /// Close the connection without an answer (the lost save).
    let dropsConnection: Bool
}

enum BridgeAdmission: Equatable, Sendable { case open, refuse, tooMany }

/// The answer table of the workspace routes: a request in, a status, a body and a delay out, with its
/// per-thread state. It holds data and replays it; it decides nothing about Yak beyond the tables.
final class StubWorkspace: @unchecked Sendable {
    private struct State {
        var variant = WorkspaceVariant.workspace
        var bridgePort = 0
        var tokenCount = 0
        var tokenThreads: [String: String] = [:]
        var usedTokens: Set<String> = []
        var savedTexts: [String: String] = [:]
        var putCount = 0
        var keptLostText: String?
        var lostSaveSpent = false
        var conflictSpent = false
        var restoreAsked: Set<String> = []
        var requestCounts: [String: Int] = [:]
        var waitingAnswers = 0
    }

    private let load: @Sendable (String) -> Data?
    private let ownOrigin: String
    private let lock = NSLock()
    private var state = State()

    /// `load` returns a scenario file's bytes by name.
    init(load: @escaping @Sendable (String) -> Data?, ownOrigin: String) {
        self.load = load
        self.ownOrigin = ownOrigin
    }

    func reset(_ variant: WorkspaceVariant, bridgePort: Int) {
        lock.withLock {
            state = State()
            state.variant = variant
            state.bridgePort = bridgePort
        }
    }

    func restoreAsked(thread id: String) {
        lock.withLock { _ = state.restoreAsked.insert(id) }
    }

    var putCount: Int { lock.withLock { state.putCount } }

    /// A token issued by `terminal-sessions` opens once; a used or unknown one is refused; a token of
    /// `term4429` is turned away with 4429.
    func admit(token: String) -> BridgeAdmission {
        lock.withLock {
            guard let thread = state.tokenThreads[token], !state.usedTokens.contains(token) else { return .refuse }
            state.usedTokens.insert(token)
            let tooMany = thread == "term4429" && state.variant.hasTerminalStates
            return tooMany ? .tooMany : .open
        }
    }

    /// nil when the request is not a workspace route. `canDrive` is the caller's thread row's.
    func answer(_ request: WorkspaceRequest, canDrive: Bool) -> WorkspaceAnswer? {
        let parts = request.path.split(separator: "/").map(String.init)
        guard parts.count >= 3, parts[0] == "threads" else { return nil }
        let thread = parts[1]
        let route = parts.dropFirst(2).joined(separator: "/")
        switch (request.method, route) {
        case ("POST", "terminal-sessions"):
            return lock.withLock { terminalSession(thread: thread, request: request, canDrive: canDrive) }
        case ("GET", "files"):
            return lock.withLock { tree(thread: thread, request: request, canDrive: canDrive) }
        case ("GET", "files/content"):
            return lock.withLock { content(request: request, canDrive: canDrive) }
        case ("PUT", "files/content"):
            return lock.withLock { save(request: request, canDrive: canDrive) }
        default:
            return nil
        }
    }

    // MARK: Terminal

    private func terminalSession(thread: String, request: WorkspaceRequest, canDrive: Bool) -> WorkspaceAnswer {
        let variant = state.variant
        let delay: Duration =
            switch variant {
            case .terminalWaking: .seconds(3)
            case .terminalSlowWake: .seconds(34)
            default: .zero
            }
        if variant.hasTerminalStates, let refusal = terminalRefusal(thread: thread) {
            return refusal
        }
        guard request.header("Origin") == ownOrigin else {
            return error(403, "origin_not_allowed", "This Yak does not accept terminal connections from this app.")
        }
        guard canDrive else { return error(403, "forbidden", "You do not have access to this.") }
        switch variant {
        case .terminalWaiting:
            state.waitingAnswers += 1
            if state.waitingAnswers <= 4 { return scenario(202, "scenario-terminal-waiting") }
        case .terminalWaitingForever:
            return scenario(202, "scenario-terminal-waiting")
        default: break
        }
        if variant.hasTerminalStates, thread == "termg0ne", state.restoreAsked.contains(thread) {
            let count = state.requestCounts["terminal-\(thread)", default: 0]
            state.requestCounts["terminal-\(thread)"] = count + 1
            if variant == .terminalRestoringForever || count < 2 {
                return scenario(202, "scenario-terminal-restoring")
            }
        }
        state.tokenCount += 1
        let token = "stub-terminal-\(state.tokenCount)"
        state.tokenThreads[token] = thread
        let expires = ISO8601DateFormatter().string(from: Date().addingTimeInterval(60))
        let object: [String: Any] = [
            "url": "ws://127.0.0.1:\(state.bridgePort)/terminal/ws?token=\(token)", "token": token,
            "expires_at": expires,
        ]
        return WorkspaceAnswer(status: 200, body: encode(object), delay: delay, dropsConnection: false)
    }

    /// The refusals of the `terminal-states` rows that answer before the Origin check can matter. A removed
    /// sandbox refuses only until its restore has been asked.
    private func terminalRefusal(thread: String) -> WorkspaceAnswer? {
        switch thread {
        case "term0rgn":
            return error(403, "origin_not_allowed", "This Yak does not accept terminal connections from this app.")
        case "termf0rb": return error(403, "forbidden", "You do not have access to this.")
        case "termc10s": return error(409, "thread_closed", "This thread is closed.")
        case "termdev1": return error(409, "thread_owned_by_device", "This thread runs on a computer.")
        case "termg0ne":
            return state.restoreAsked.contains(thread)
                ? nil : error(409, "sandbox_destroyed", "The sandbox was removed. Restore it to open a shell.")
        case "termn0sb": return error(409, "no_sandbox", "This thread has no sandbox yet.")
        case "termb1kd":
            return error(
                409, "thread_blocked", "The sandbox could not be restored.", details: ["reason": "restore_failed"])
        default: return nil
        }
    }

    // MARK: Files

    private func tree(thread: String, request: WorkspaceRequest, canDrive: Bool) -> WorkspaceAnswer {
        let variant = state.variant
        if variant == .filesStates, let answer = filesState(thread: thread) { return answer }
        let delay: Duration =
            switch variant {
            case .terminalWaking: .seconds(3)
            case .filesSlow: .seconds(14)
            default: .zero
            }
        let path = request.query["path"] ?? ""
        if request.query["scope"] == "sandbox" {
            guard canDrive else { return error(403, "forbidden", "You do not have access to this.") }
            return withDelay(delay, listing("scenario-file-tree-root") { $0["root"] = "/" })
        }
        switch path {
        case "":
            return withDelay(delay, scenario(200, "scenario-file-tree-root"))
        case "src":
            return withDelay(
                delay,
                listing("scenario-file-tree-src") {
                    var entries = $0["entries"] as? [[String: Any]] ?? []
                    entries.append(Self.entry("src/Gone.php", size: 812))
                    $0["entries"] = entries
                })
        case "tests":
            return withDelay(
                delay,
                listing("scenario-file-tree-src") {
                    $0["path"] = "tests"
                    $0["entries"] = [Self.entry("tests/ImporterTest.php", size: 640)]
                    $0["truncated"] = false
                })
        case "empty":
            return withDelay(
                delay,
                listing("scenario-file-tree-src") {
                    $0["path"] = "empty"
                    $0["entries"] = [[String: Any]]()
                    $0["truncated"] = false
                })
        default:
            return error(404, "not_found", "This was not found.")
        }
    }

    /// The per-thread answers of `files-states`; nil when the thread has no special state or has reached ready.
    private func filesState(thread: String) -> WorkspaceAnswer? {
        let count = state.requestCounts[thread, default: 0]
        state.requestCounts[thread] = count + 1
        switch thread {
        case "fwak1ng0":
            return count < 2 ? scenario(409, "scenario-files-waking") : nil
        case "fs10t000":
            return unavailable("scenario-files-waking", message: "The sandbox is waiting for a free slot.") {
                $0["waitingForSlot"] = true
            }
        case "fas1eep0":
            return scenario(409, "scenario-files-asleep")
        case "fg0ne000":
            if !state.restoreAsked.contains(thread) { return scenario(409, "scenario-files-destroyed") }
            let restoring = state.requestCounts["restoring-\(thread)", default: 0]
            state.requestCounts["restoring-\(thread)"] = restoring + 1
            return restoring < 2 ? restoringAnswer() : nil
        case "fr3st0re":
            return restoringAnswer()
        case "fc10sed0":
            return unavailable("scenario-files-waking", message: "This thread is closed, so its sandbox is gone.") {
                $0["state"] = "closed_destroyed"
                $0["canRestore"] = false
                $0["waitingForSlot"] = false
                $0["restoring"] = false
            }
        case "fdev1ce0":
            return unavailable(
                "scenario-files-waking", message: "This thread runs on a computer, so its files are there."
            ) {
                $0["state"] = "on_device"
                $0["canRestore"] = false
                $0["waitingForSlot"] = false
                $0["restoring"] = false
            }
        case "fbr0ken0":
            return error(503, "unavailable", "The sandbox did not answer. Try again in a moment.")
        default:
            return nil
        }
    }

    private func restoringAnswer() -> WorkspaceAnswer {
        unavailable("scenario-files-waking", message: "The sandbox is being restored.") { $0["restoring"] = true }
    }

    private func content(request: WorkspaceRequest, canDrive: Bool) -> WorkspaceAnswer {
        let path = request.query["path"] ?? ""
        let readOnly = state.variant == .filesReadOnly
        if state.variant == .fileSlowRead {
            return withDelay(.seconds(14), contentAnswer(path: path, readOnly: readOnly))
        }
        return contentAnswer(path: path, readOnly: readOnly)
    }

    private func contentAnswer(path: String, readOnly: Bool) -> WorkspaceAnswer {
        switch path {
        case "src/logo.png": return scenario(200, "scenario-file-binary")
        case "src/sample.csv": return scenario(200, "scenario-file-too-large")
        case "src/Importer.php":
            if let saved = state.savedTexts[path] {
                return edited("scenario-file-text", text: saved, path: path, readOnly: readOnly)
            }
            return edited("scenario-file-text", text: nil, path: path, readOnly: readOnly)
        case ".yak/preview.yml":
            return edited(
                "scenario-file-text", text: state.savedTexts[path] ?? "port: 5173\ncold_start: npm run dev\n",
                path: path, readOnly: readOnly, language: "yaml", hash: Self.hash(path.count))
        case "app/Uploads/RetryUpload.php":
            return edited(
                "scenario-file-text", text: state.savedTexts[path] ?? "<?php\n\nfinal class RetryUpload\n{\n}\n",
                path: path, readOnly: readOnly, language: "php", hash: Self.hash(path.count))
        case "README.md", "tests/ImporterTest.php":
            let text =
                state.savedTexts[path]
                ?? (path == "README.md"
                    ? "# Example API\n\nA small importer.\n"
                    : "<?php\nnamespace Example\\Api\\Tests;\n\nfinal class ImporterTest\n{\n}\n")
            let language = path == "README.md" ? "markdown" : "php"
            return edited(
                "scenario-file-text", text: text, path: path, readOnly: readOnly, language: language,
                hash: state.savedTexts[path] == nil ? Self.hash(path.count) : Self.savedHash)
        default:
            return error(404, "not_found", "This was not found.")
        }
    }

    private func save(request: WorkspaceRequest, canDrive: Bool) -> WorkspaceAnswer {
        state.putCount += 1
        let sent = (try? JSONSerialization.jsonObject(with: request.body ?? Data())) as? [String: Any] ?? [:]
        let path = sent["path"] as? String ?? ""
        let text = sent["content"] as? String ?? ""
        switch state.variant {
        case .filesSaveClosed: return error(409, "thread_closed", "This thread is closed.")
        case .filesSaveAsleep: return scenario(409, "scenario-files-asleep")
        default: break
        }
        if !canDrive || state.variant == .filesReadOnly {
            return error(403, "forbidden", "You do not have access to this.")
        }
        if state.variant == .filesConflictDeleted, !state.conflictSpent {
            state.conflictSpent = true
            return conflictDeleted()
        }
        if state.variant == .filesConflict, !state.conflictSpent {
            state.conflictSpent = true
            return scenario(409, "scenario-file-conflict")
        }
        if state.variant == .filesLostSave {
            if !state.lostSaveSpent {
                state.lostSaveSpent = true
                state.keptLostText = text
                state.savedTexts[path] = text
                return WorkspaceAnswer(status: 200, body: Data(), delay: .zero, dropsConnection: true)
            }
            if let kept = state.keptLostText, kept == text {
                state.keptLostText = nil
                return conflictHolding(text: kept, path: path)
            }
        }
        state.savedTexts[path] = text
        guard var object = object("scenario-file-saved") else { return missing("scenario-file-saved") }
        var file = object["file"] as? [String: Any] ?? [:]
        file["content"] = text
        file["path"] = path
        file["sha256"] = Self.savedHash
        file["size"] = text.utf8.count
        object["file"] = file
        let delay: Duration = state.variant == .fileSlowSave ? .seconds(14) : .zero
        return WorkspaceAnswer(status: 200, body: encode(object), delay: delay, dropsConnection: false)
    }

    /// The conflict answer for a file that was deleted meanwhile: `details.file` is null.
    private func conflictDeleted() -> WorkspaceAnswer {
        guard var object = object("scenario-file-conflict") else { return missing("scenario-file-conflict") }
        var details = object["details"] as? [String: Any] ?? [:]
        details["file"] = NSNull()
        object["details"] = details
        return WorkspaceAnswer(status: 409, body: encode(object), delay: .zero, dropsConnection: false)
    }

    private func conflictHolding(text: String, path: String) -> WorkspaceAnswer {
        guard var object = object("scenario-file-conflict") else { return missing("scenario-file-conflict") }
        var details = object["details"] as? [String: Any] ?? [:]
        var file = details["file"] as? [String: Any] ?? [:]
        file["content"] = text
        file["path"] = path
        file["sha256"] = Self.savedHash
        file["size"] = text.utf8.count
        details["file"] = file
        object["details"] = details
        return WorkspaceAnswer(status: 409, body: encode(object), delay: .zero, dropsConnection: false)
    }

    private func edited(
        _ name: String, text: String?, path: String, readOnly: Bool, language: String? = nil, hash: String? = nil
    ) -> WorkspaceAnswer {
        guard var object = object(name) else { return missing(name) }
        if let text {
            object["content"] = text
            object["size"] = text.utf8.count
            object["sha256"] = hash ?? Self.savedHash
        }
        object["path"] = path
        if let language { object["language"] = language }
        if readOnly { object["canEdit"] = false }
        return WorkspaceAnswer(status: 200, body: encode(object), delay: .zero, dropsConnection: false)
    }

    // MARK: Building blocks

    /// "5e5e5e5e" eight times: the hash the stub gives a text it saved.
    private static let savedHash = String(repeating: "5e5e5e5e", count: 8)

    private static func hash(_ seed: Int) -> String {
        String(repeating: String(format: "%08x", 0x0a1b_2c3d &+ seed), count: 8)
    }

    private static func entry(_ path: String, size: Int) -> [String: Any] {
        [
            "name": path.split(separator: "/").last.map(String.init) ?? path, "path": path, "isDirectory": false,
            "isWritable": true, "size": size,
        ]
    }

    private func object(_ name: String) -> [String: Any]? {
        guard let data = load(name) else { return nil }
        return (try? JSONSerialization.jsonObject(with: data)) as? [String: Any]
    }

    private func encode(_ object: [String: Any]) -> Data {
        (try? JSONSerialization.data(withJSONObject: object, options: [.sortedKeys])) ?? Data()
    }

    private func missing(_ name: String) -> WorkspaceAnswer {
        error(500, "stub_scenario_missing", "The stub has no scenario data named \(name).")
    }

    private func scenario(_ status: Int, _ name: String) -> WorkspaceAnswer {
        guard let data = load(name) else { return missing(name) }
        return WorkspaceAnswer(status: status, body: data, delay: .zero, dropsConnection: false)
    }

    private func listing(_ name: String, edit: (inout [String: Any]) -> Void) -> WorkspaceAnswer {
        guard var object = object(name) else { return missing(name) }
        edit(&object)
        return WorkspaceAnswer(status: 200, body: encode(object), delay: .zero, dropsConnection: false)
    }

    /// A 409 `sandbox_unavailable` built from a scenario, with its `details` changed.
    private func unavailable(
        _ name: String, message: String? = nil, edit: (inout [String: Any]) -> Void
    ) -> WorkspaceAnswer {
        guard var object = object(name) else { return missing(name) }
        if let message { object["message"] = message }
        var details = object["details"] as? [String: Any] ?? [:]
        edit(&details)
        object["details"] = details
        return WorkspaceAnswer(status: 409, body: encode(object), delay: .zero, dropsConnection: false)
    }

    private func withDelay(_ delay: Duration, _ answer: WorkspaceAnswer) -> WorkspaceAnswer {
        WorkspaceAnswer(status: answer.status, body: answer.body, delay: delay, dropsConnection: false)
    }

    private func error(_ status: Int, _ code: String, _ message: String, details: [String: Any] = [:])
        -> WorkspaceAnswer
    {
        var body: [String: Any] = ["error": code, "message": message]
        if !details.isEmpty { body["details"] = details }
        return WorkspaceAnswer(status: status, body: encode(body), delay: .zero, dropsConnection: false)
    }
}

extension WorkspaceVariant {
    fileprivate var hasTerminalStates: Bool { self == .terminalStates || self == .terminalRestoringForever }
}
