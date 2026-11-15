import Foundation

/// One preview play; the raw value is the stub scenario name that selects it.
enum StubPreviewPlay: String, CaseIterable, Sendable {
    case ready = "preview-ready"
    case starting = "preview-starting"
    case waking = "preview-waking"
    case waitingForSlot = "preview-waiting-for-slot"
    case failing = "preview-failing"
    case fixing = "preview-fixing"
    case noManifest = "preview-no-manifest"
    case setupEnded = "preview-setup-ended"
    case invalidManifest = "preview-invalid-manifest"
    case destroyed = "preview-destroyed"
    case restoreFailed = "preview-restore-failed"
    case readOnly = "preview-read-only"
    case closed = "preview-closed"
    case localMachine = "preview-local-machine"
    case unknown = "preview-unknown"
    case slow = "preview-slow"
    case signInRefused = "preview-sign-in-refused"
    case pageDown = "preview-page-down"
    case working = "preview-working"
    case question = "preview-question"
}

/// Lock-protected per-thread preview state: the warm count, the status answers given, whether a restore was asked,
/// the issued tickets and which were used, the play count (the cookie generation) and the log.
/// It decides nothing about Yak beyond the play tables; the counters only let a test prove what the app did.
final class StubPreview: @unchecked Sendable {
    private struct ThreadState {
        var warmCount = 0
        var restoreAsked = false
        var statusAnswersSinceRestore = 0
        var fixAsked = false
    }

    static let statusDelay = Duration.seconds(14)

    private let scenarioData: @Sendable (String) -> Data?
    private let lock = NSLock()
    private var play: StubPreviewPlay?
    private var playCount = 0
    private var threads: [String: ThreadState] = [:]
    private var ticketCount = 0
    private var issuedTickets: Set<String> = []
    private var usedTickets: Set<String> = []
    private var lines: [String] = []
    private var pagesBase = "http://127.0.0.1:0"

    init(scenarioData: @escaping @Sendable (String) -> Data?) {
        self.scenarioData = scenarioData
    }

    /// A new play resets everything but the play count, which goes up by one.
    func begin(_ play: StubPreviewPlay?) {
        lock.withLock {
            self.play = play
            playCount += 1
            threads = [:]
            ticketCount = 0
            issuedTickets = []
            usedTickets = []
            lines = []
        }
    }

    func setPagesBaseURL(_ url: String) { lock.withLock { pagesBase = url } }

    var pagesBaseURL: String { lock.withLock { pagesBase } }
    var cookieValue: String { lock.withLock { String(playCount) } }
    var playing: StubPreviewPlay? { lock.withLock { play } }
    var log: [String] { lock.withLock { lines } }

    func record(_ line: String) { lock.withLock { lines.append(line) } }

    func messageArrived(threadID: String, intent: String?) {
        guard intent == "fix_preview" else { return }
        lock.withLock { threads[threadID, default: ThreadState()].fixAsked = true }
    }

    /// Marks an issued ticket used; false when it is unknown or was used already.
    func redeem(ticket: String) -> Bool {
        lock.withLock {
            guard issuedTickets.contains(ticket), !usedTickets.contains(ticket) else { return false }
            usedTickets.insert(ticket)
            return true
        }
    }

    /// The answer for a preview API route; nil for any other route and when no preview play is running.
    func reply(to request: StubRequest) -> StubReply? {
        guard request.path.hasPrefix(Scenario.apiPrefix), let play else { return nil }
        let parts = request.path.dropFirst(Scenario.apiPrefix.count).split(separator: "/").map(String.init)
        guard parts.count == 4, parts[0] == "threads", parts[2] == "preview" else { return nil }
        let threadID = parts[1]
        switch (request.method, parts[3]) {
        case ("GET", "status"): return status(threadID: threadID, play: play)
        case ("POST", "warm"):
            lock.withLock { threads[threadID, default: ThreadState()].warmCount += 1 }
            record("warm")
            return noContent
        case ("POST", "heartbeat"):
            record("heartbeat")
            return noContent
        case ("POST", "ticket"): return ticket(request)
        case ("POST", "restore"):
            lock.withLock { threads[threadID, default: ThreadState()].restoreAsked = true }
            record("restore")
            return file("scenario-preview-restore", status: 202) { _ in }
        default: return nil
        }
    }

    private var noContent: StubReply {
        StubReply(answer: .reply(status: 204, contentType: "application/json", body: Data()))
    }

    private func missing() -> StubReply {
        StubReply(answer: Scenario.error(500, "server_error", "Scenario data missing."))
    }

    private func file(_ name: String, status: Int = 200, edit: (inout [String: Any]) -> Void) -> StubReply {
        guard let data = scenarioData(name) else { return missing() }
        do {
            guard var object = try JSONSerialization.jsonObject(with: data) as? [String: Any] else { return missing() }
            edit(&object)
            let body = try JSONSerialization.data(withJSONObject: object)
            return StubReply(answer: .reply(status: status, contentType: "application/json", body: body))
        } catch {
            return missing()
        }
    }

    private func status(threadID: String, play: StubPreviewPlay) -> StubReply {
        let (name, edit) = lock.withLock { () -> (String, StateEdit) in
            var state = threads[threadID, default: ThreadState()]
            var edit = StateEdit.none
            let name: String
            switch play {
            case .ready, .slow, .signInRefused, .pageDown, .working, .question: name = "ready"
            case .starting: name = state.warmCount >= 2 ? "ready" : "starting"
            case .waking:
                name = state.warmCount == 0 ? "waking" : state.warmCount < 3 ? "starting" : "ready"
            case .waitingForSlot: name = "waiting-for-slot"
            case .failing: name = state.fixAsked ? "fixing" : "failing"
            case .fixing: name = "fixing"
            case .noManifest: name = "no-manifest"
            case .setupEnded:
                name = "no-manifest"
                edit = .setupEnded
            case .invalidManifest: name = "invalid-manifest"
            case .destroyed:
                if state.restoreAsked {
                    name = state.statusAnswersSinceRestore < 2 ? "waking" : "ready"
                    state.statusAnswersSinceRestore += 1
                } else {
                    name = "destroyed"
                }
            case .restoreFailed: name = "starting"
            case .readOnly: name = "destroyed"
            case .closed: name = "closed"
            case .localMachine: name = "local-machine"
            case .unknown:
                name = "ready"
                edit = .unknownState
            }
            threads[threadID] = state
            return (name, edit)
        }
        record("status \(name)")
        var reply = file("scenario-preview-\(name)") { object in
            if object["url"] is String { object["url"] = pagesBaseURL + "/" }
            switch edit {
            case .none: break
            case .setupEnded:
                var setup = object["setup"] as? [String: Any] ?? [:]
                setup["endedWithoutManifest"] = true
                object["setup"] = setup
            case .unknownState:
                object["state"] = "hibernating"
                object["url"] = NSNull()
            }
        }
        if play == .slow { reply.delay = Self.statusDelay }
        return reply
    }

    private enum StateEdit { case none, setupEnded, unknownState }

    private func ticket(_ request: StubRequest) -> StubReply {
        let path: String? = {
            do {
                let body = try JSONSerialization.jsonObject(with: request.body) as? [String: Any]
                return body?["path"] as? String
            } catch {
                return nil
            }
        }()
        guard let path, path.hasPrefix("/") else {
            return StubReply(
                answer: Scenario.error(
                    422, "validation_failed", "The path is not valid.",
                    details: ["errors": ["path": ["The path must start with a slash."]]]))
        }
        let (number, base) = lock.withLock { () -> (Int, String) in
            ticketCount += 1
            issuedTickets.insert("stub-ticket-\(ticketCount)")
            return (ticketCount, pagesBase)
        }
        record("ticket \(path)")
        return file("scenario-preview-ticket") { object in
            object["url"] = "\(base)/_yak/auth?ticket=stub-ticket-\(number)&to=\(Self.percentEncoded(path))"
        }
    }

    static func percentEncoded(_ text: String) -> String {
        text.addingPercentEncoding(withAllowedCharacters: CharacterSet.alphanumerics.union(.init(charactersIn: "-._~")))
            ?? ""
    }
}
