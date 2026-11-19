import Foundation

/// The work of a thread: the cards above the composer (stopped, repository question, early warning, co-owner), the
/// menu actions, milestones, retry rows, channel messages, a legacy thread and a moved one. Every `work-` scenario
/// plays the paired scenario underneath and answers the thread's show from its own data files. An action route
/// answers `{status, ...show}`, and the next show of the thread is the one the answer carried.
final class StubThreadWork: StubFeature, @unchecked Sendable {
    let name = "work"
    let scenarios = [
        "work-stopped", "work-stopped-retry-fails", "work-stopped-reply", "work-expired-co-owner",
        "work-co-owner-decide", "work-co-owner-link", "work-co-owner-requester", "work-co-owner-reader",
        "work-early-warning", "work-repository-question", "work-repository-taken", "work-move", "work-milestones",
        "work-via", "work-legacy", "work-drained", "work-offline", "work-slow-approve", "work-setup", "work-cancelled",
        "work-co-owner-no-access", "work-co-owner-check-failed", "work-co-owner-link-stale",
    ]
    let scenarioDataNames = [
        "scenario-work-stopped", "scenario-work-working", "scenario-work-expired", "scenario-work-needs-you-stopped",
        "scenario-work-reply-retried", "scenario-work-co-owner", "scenario-work-co-owner-approved",
        "scenario-work-early-warning", "scenario-work-early-warning-tagged", "scenario-work-repository-question",
        "scenario-work-repository-answered", "scenario-work-finished", "scenario-work-moved",
        "scenario-work-moved-new", "scenario-work-milestones", "scenario-work-via", "scenario-work-legacy",
        "scenario-work-legacy-continued", "scenario-work-drained-queued", "scenario-work-drained-taken",
        "scenario-work-setup", "scenario-work-cancelled", "scenario-work-co-owner-no-access",
        "scenario-work-co-owner-check-failed",
    ]

    static let threadID = "d0e515ef"
    static let movedThreadID = "m0v3d000"
    static let continuedThreadID = "c0n71nue"
    private static let connectURL = "https://yak.example.com/connect/github"

    private struct State {
        /// The scenario data file the main thread's show comes from now.
        var current = ""
        var counts: [String: Int] = [:]
        var lastBody = ""
        var isOnline = true
    }
    private let state = Locked(State())
    private let publisher = Locked<(@Sendable (String, String) -> Void)?>(nil)
    private let playbacks = Locked<[Task<Void, Never>]>([])
    /// How long after the first subscription of `work-drained` the queued note is taken.
    private let drainDelay: Duration

    init(drainDelay: Duration = .seconds(3)) { self.drainDelay = drainDelay }

    func attach(publish: @escaping @Sendable (_ thread: String, _ json: String) -> Void) { publisher.set(publish) }
    func setOnline(_ isOnline: Bool) { state.mutate { $0.isOnline = isOnline } }

    func reset() {
        for task in playbacks.mutate({ tasks -> [Task<Void, Never>] in
            defer { tasks = [] }
            return tasks
        }) {
            task.cancel()
        }
        state.mutate {
            let isOnline = $0.isOnline
            $0 = State()
            $0.isOnline = isOnline
        }
    }

    func played(scenario: String) {
        guard let first = Self.firstShow[scenario] else { return }
        state.mutate { $0.current = first }
    }

    private static let firstShow: [String: String] = [
        "work-stopped": "scenario-work-stopped", "work-stopped-retry-fails": "scenario-work-stopped",
        "work-stopped-reply": "scenario-work-needs-you-stopped", "work-expired-co-owner": "scenario-work-expired",
        "work-co-owner-decide": "scenario-work-co-owner", "work-co-owner-link": "scenario-work-co-owner",
        "work-co-owner-link-stale": "scenario-work-co-owner",
        "work-co-owner-requester": "scenario-work-co-owner", "work-co-owner-reader": "scenario-work-co-owner",
        "work-early-warning": "scenario-work-early-warning",
        "work-repository-question": "scenario-work-repository-question",
        "work-repository-taken": "scenario-work-repository-question", "work-move": "scenario-work-finished",
        "work-milestones": "scenario-work-milestones", "work-via": "scenario-work-via",
        "work-legacy": "scenario-work-legacy", "work-drained": "scenario-work-drained-queued",
        "work-offline": "scenario-work-stopped", "work-slow-approve": "scenario-work-co-owner",
        "work-setup": "scenario-work-setup", "work-cancelled": "scenario-work-cancelled",
        "work-co-owner-no-access": "scenario-work-co-owner-no-access",
        "work-co-owner-check-failed": "scenario-work-co-owner-check-failed",
    ]

    func control(_ request: StubRequest) -> StubReply? {
        guard request.path == "/__stub/work/last-request" else { return nil }
        let body = state.get().lastBody
        return StubReply(answer: .reply(status: 200, contentType: "text/plain", body: Data(body.utf8)))
    }

    /// `work-drained`: the first subscription plays the turn that takes the queued note, three seconds in. The show
    /// changes first, then a `thread` event with a `showKey` tells the app to fetch it.
    func subscribed(thread id: String, scenario: String, fixtures: StubFixtures) {
        let isFirst = state.mutate { state -> Bool in
            state.counts["drain", default: 0] += 1
            return state.counts["drain"] == 1
        }
        guard scenario == "work-drained", id == Self.threadID, isFirst else { return }
        let task = Task { [self] in
            if (try? await Task.sleep(for: drainDelay)) == nil { return }
            while !state.get().isOnline {
                if (try? await Task.sleep(for: .milliseconds(25))) == nil { return }
            }
            state.mutate { $0.current = "scenario-work-drained-taken" }
            let event: [String: Any] = [
                "sequence": 15, "type": "thread",
                "data": [
                    "status": "open", "sandboxState": "running", "runnerState": "running", "showKey": "drained00001",
                ],
                "createdAt": ISO8601DateFormatter().string(from: Date()),
            ]
            guard let bytes = try? JSONSerialization.data(withJSONObject: event, options: [.sortedKeys]) else { return }
            publisher.get()?(id, String(decoding: bytes, as: UTF8.self))
        }
        playbacks.mutate { $0.append(task) }
    }

    // MARK: Routes

    func answer(route: String, request: StubRequest, context: StubContext) -> StubReply? {
        guard scenarios.contains(context.scenario) else { return nil }
        let prefix = "threads/"
        let parts = route.split(separator: " ", maxSplits: 1).map(String.init)
        guard parts.count == 2, parts[1].hasPrefix(prefix) else { return nil }
        let segments = parts[1].dropFirst(prefix.count).split(separator: "/").map(String.init)
        guard let id = segments.first else { return nil }
        let verb = segments.dropFirst().joined(separator: "/")
        if parts[0] == "GET", verb.isEmpty { return show(of: id, context: context) }
        guard id == Self.threadID else { return nil }
        if parts[0] == "GET", verb == "co-owner/candidates" { return candidates() }
        guard parts[0] == "POST" else { return nil }
        state.mutate { $0.lastBody = String(decoding: request.body, as: UTF8.self) }
        if verb == "messages" { return message(context: context) }
        return action(verb, request: request, context: context)
    }

    private func show(of id: String, context: StubContext) -> StubReply? {
        switch id {
        case Self.movedThreadID: return reply(file: "scenario-work-moved-new", context: context)
        case Self.continuedThreadID: return reply(file: "scenario-work-legacy-continued", context: context)
        case Self.threadID: return reply(file: state.get().current, context: context)
        default: return nil
        }
    }

    private func reply(file: String, status: String? = nil, delay: Duration = .zero, context: StubContext) -> StubReply
    {
        var body = Self.variant(context.fixtures.scenario(file), for: context.scenario)
        if let status { body["status"] = status }
        var reply = Self.json(body)
        reply.delay = delay
        return reply
    }

    /// The show of a scenario that plays one data file for several viewers.
    private static func variant(_ show: [String: Any], for scenario: String) -> [String: Any] {
        var show = show
        guard var panel = show["coOwner"] as? [String: Any], var viewer = panel["viewer"] as? [String: Any],
            panel["status"] as? String == "pending"
        else { return show }
        switch scenario {
        case "work-co-owner-link":
            viewer["isLinked"] = false
            viewer["access"] = "unlinked"
        case "work-co-owner-requester":
            viewer["isRequester"] = true
            panel["viewerIsAsked"] = false
        case "work-co-owner-reader":
            show["canDrive"] = false
            panel["canDecide"] = false
        default:
            break
        }
        panel["viewer"] = viewer
        show["coOwner"] = panel
        return show
    }

    /// A decision on the co-owner request: the request is decided, no card, Yak works on.
    private func decided(_ status: String, context: StubContext) -> StubReply {
        var body = Self.variant(context.fixtures.scenario("scenario-work-co-owner-approved"), for: context.scenario)
        var panel = body["coOwner"] as? [String: Any] ?? [:]
        panel["status"] = status
        if status != "approved" { panel["prUrl"] = NSNull() }
        body["coOwner"] = panel
        body["status"] = status
        state.mutate { $0.current = "scenario-work-co-owner-approved" }
        return Self.json(body)
    }

    private func advance(_ status: String, to file: String, context: StubContext, delay: Duration = .zero) -> StubReply
    {
        state.mutate { $0.current = file }
        return reply(file: file, status: status, delay: delay, context: context)
    }

    private func action(_ verb: String, request: StubRequest, context: StubContext) -> StubReply? {
        let scenario = context.scenario
        let count = state.mutate { state -> Int in
            state.counts[verb, default: 0] += 1
            return state.counts[verb] ?? 1
        }
        switch verb {
        case "retry", "setup-and-continue":
            if scenario == "work-stopped-retry-fails", count == 1 {
                return error(409, "task_not_retryable", "This task cannot be retried right now.")
            }
            return advance("queued", to: "scenario-work-working", context: context)
        case "cancel":
            var body = context.fixtures.scenario("scenario-work-stopped")
            body["stopped"] = [
                "kind": "cancelled", "summary": "This request was cancelled.", "detail": NSNull(),
                "nextStep": "Start a new request to try again.", "isWaitingForSetup": false,
            ]
            body["actions"] = Self.noActions
            body["status"] = "cancelled"
            return Self.json(body)
        case "request-review":
            return error(409, "review_not_requested", "A review is already running on this pull request.")
        case "retry-render":
            return reply(file: state.get().current, status: "rendering", context: context)
        case "move":
            state.mutate { $0.current = "scenario-work-moved" }
            return reply(file: "scenario-work-moved-new", status: "moved", context: context)
        case "repository-answer":
            if scenario == "work-repository-taken" {
                return error(409, "repository_already_picked", "A repository was already chosen for this thread.")
            }
            return advance("started", to: "scenario-work-repository-answered", context: context)
        case "co-owner/approve":
            if scenario == "work-co-owner-link" || scenario == "work-co-owner-link-stale" {
                return error(
                    409, "not_linked", "Link your GitHub account to decide.", details: ["connectUrl": Self.connectURL])
            }
            var decision = decided("approved", context: context)
            decision.delay = scenario == "work-slow-approve" ? .seconds(20) : .seconds(3)
            return decision
        case "co-owner/request-changes": return decided("changes_requested", context: context)
        case "co-owner/reject": return decided("rejected", context: context)
        case "co-owner/withdraw": return decided("withdrawn", context: context)
        case "co-owner/check-access": return reply(file: state.get().current, status: "checked", context: context)
        case "co-owner/tag": return advance("tagged", to: "scenario-work-early-warning-tagged", context: context)
        case "co-owner/decline": return advance("declined", to: "scenario-work-working", context: context)
        default: return nil
        }
    }

    /// A reply to a stopped thread says what became of it and has no message row; a reply to a legacy thread names the
    /// thread that carries on.
    private func message(context: StubContext) -> StubReply? {
        switch context.scenario {
        case "work-stopped", "work-stopped-reply", "work-stopped-retry-fails", "work-offline":
            state.mutate { $0.current = "scenario-work-reply-retried" }
            return Self.json(["status": "sent", "uuid": NSNull(), "notice": "Trying again with your note."])
        case "work-legacy":
            return Self.json(["status": "sent", "uuid": NSNull(), "threadId": Self.continuedThreadID])
        default:
            return nil
        }
    }

    /// The first ask runs out of time and lists some people; the next lists everyone.
    private func candidates() -> StubReply {
        let count = state.mutate { state -> Int in
            state.counts["candidates", default: 0] += 1
            return state.counts["candidates"] ?? 1
        }
        let people: [[String: Any]] = [["id": 4, "name": "Second person"], ["id": 5, "name": "Third person"]]
        if count == 1 { return Self.json(["candidates": Array(people.prefix(1)), "isComplete": false]) }
        return Self.json(["candidates": people, "isComplete": true])
    }

    // MARK: Replies

    private static var noActions: [String: Any] {
        [
            "canStop": false, "canRetry": false, "canCancel": false, "canMove": false, "moveTargets": [String](),
            "canRequestReview": false, "canRetryRender": false,
        ]
    }

    private func error(_ status: Int, _ code: String, _ message: String, details: [String: Any] = [:]) -> StubReply {
        StubReply(answer: Scenario.error(status, code, message, details: details))
    }

    private static func json(_ object: [String: Any]) -> StubReply {
        let data = (try? JSONSerialization.data(withJSONObject: object)) ?? Data("{}".utf8)
        return StubReply(answer: .reply(status: 200, contentType: "application/json", body: data))
    }
}
