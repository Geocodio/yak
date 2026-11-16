import Foundation

/// The Overview's part of the stub: the stack, the environment and the share of a thread, and the actions on them.
/// It decides nothing about Yak beyond the scenario tables; it only echoes what the app sent, so a test can prove
/// what the app did. A new scenario resets everything.
final class StubOverview: StubFeature, @unchecked Sendable {
    static let rootID = "s7ackr00"
    static let childID = "b4ckf111"
    static let createdChildID = "n3wch11d"
    static let slowDelay = Duration.seconds(14)
    static let busyAnswers = 2

    static let scenarioNames = [
        "overview-stack", "overview-stopped", "overview-full", "overview-no-sub-threads", "overview-setting-up",
        "overview-new-thread", "overview-recipient", "overview-refusals", "overview-busy", "overview-slow",
        "overview-private", "overview-broken",
    ]

    let name = "overview"
    let scenarios = StubOverview.scenarioNames

    /// Hand-written data only; no server fixture has these names, except the three that `server-fixtures` reads.
    let fixturePairs = [
        StubFixturePair(server: "stack", scenario: "scenario-overview-busy-stack"),
        StubFixturePair(server: "stack-child", scenario: "scenario-overview-child-view"),
        StubFixturePair(server: "thread-environment", scenario: "scenario-overview-environment"),
        StubFixturePair(server: "thread-share", scenario: "scenario-overview-share"),
        StubFixturePair(server: "overview-full-stack", scenario: "scenario-overview-full-stack"),
        StubFixturePair(server: "overview-stopped-stack", scenario: "scenario-overview-stopped-stack"),
        StubFixturePair(server: "overview-recipient-stack", scenario: "scenario-overview-recipient-stack"),
        StubFixturePair(server: "overview-empty-stack", scenario: "scenario-overview-no-sub-threads"),
        StubFixturePair(server: "overview-child-show", scenario: "scenario-overview-child-show"),
        StubFixturePair(server: "overview-thread-list", scenario: "scenario-overview-thread-list"),
        StubFixturePair(server: "overview-thread-show", scenario: "scenario-overview-thread-show"),
        StubFixturePair(server: "overview-environment-new", scenario: "scenario-overview-environment-new"),
        StubFixturePair(
            server: "overview-environment-setting-up", scenario: "scenario-overview-environment-setting-up"),
        StubFixturePair(
            server: "overview-environment-setup-failed", scenario: "scenario-overview-environment-setup-failed"),
        StubFixturePair(server: "overview-sub-thread-created", scenario: "scenario-overview-sub-thread-created"),
    ]

    private struct State {
        var addedChildren: [[String: Any]] = []
        var sendKeys: [String: String] = [:]
        var isStopped = false
        var limit: Int?
        var takenOver: Set<String> = []
        var dropped: Set<String> = []
        var environmentReads = 0
        var retriedRepositories: [String: Int] = [:]
        var share: (mode: String, userIDs: [Int])?
        var actionCounts: [String: Int] = [:]
        var lastRequest = ""
    }

    private let lock = NSLock()
    private var state = State()

    func reset() { lock.withLock { state = State() } }

    func control(_ request: StubRequest) -> StubReply? {
        guard request.path == "/__stub/overview/last-request" else { return nil }
        return StubReply(
            answer: .reply(
                status: 200, contentType: "text/plain", body: Data(lock.withLock { state.lastRequest }.utf8)))
    }

    func answer(route: String, request: StubRequest, context: StubContext) -> StubReply? {
        let parts = route.split(separator: " ", maxSplits: 1).map(String.init)
        guard parts.count == 2 else { return nil }
        let method = parts[0]
        let path = parts[1].split(separator: "/").map(String.init)
        guard path.first == "threads" else { return nil }
        let scenario = context.scenario
        let isOverview = Self.scenarioNames.contains(scenario)
        guard isOverview || scenario == Scenario.serverFixtures.rawValue else { return nil }
        let isServerFixtures = !isOverview

        if path.count == 1, method == "GET", isOverview {
            return json(context.fixtures.scenario("scenario-overview-thread-list", baseURL: context.baseURL))
        }
        guard path.count >= 2 else { return nil }
        let id = path[1]
        let rest = Array(path.dropFirst(2))
        let isOwnThread = isKnownThread(id)

        if method == "GET", rest.isEmpty, isOverview, isOwnThread {
            return json(show(id: id, scenario: scenario, context: context))
        }
        guard let action = overviewAction(method: method, rest: rest) else {
            // Events, messages and the rest of a thread's routes play the core thread under its own id.
            if isOverview, isOwnThread, id != "new" {
                var rewritten = path
                rewritten[1] = "d0e515ef"
                return Scenario.paired.threadReply(
                    route: "\(method) \(rewritten.joined(separator: "/"))", request: request,
                    baseURL: context.baseURL, fixtures: context.fixtures, threads: context.threads)
            }
            return nil
        }
        guard isOwnThread || isServerFixtures else { return nil }
        if !action.isRead { noteRequest(method: method, path: path, body: request.body) }
        if isServerFixtures { return serverFixtureReply(action: action, id: id, request: request, context: context) }
        return scenarioReply(action: action, id: id, scenario: scenario, request: request, context: context)
    }

    // MARK: Routes

    private enum Action: Equatable {
        case stack, addChild, stop, limit, takeOver, split, drop, environment
        case retry(String)
        case share, updateShare

        /// The key under which a busy scenario counts its answers; reads never count.
        var isRead: Bool { self == .stack || self == .environment || self == .share }
    }

    private func overviewAction(method: String, rest: [String]) -> Action? {
        switch (method, rest) {
        case ("GET", ["stack"]): return .stack
        case ("POST", ["stack", "children"]): return .addChild
        case ("POST", ["stack", "stop"]): return .stop
        case ("PATCH", ["stack", "limit"]): return .limit
        case ("POST", ["stack", "take-over"]): return .takeOver
        case ("POST", ["stack", "split"]): return .split
        case ("POST", ["stack", "drop"]): return .drop
        case ("GET", ["environment"]): return .environment
        case ("POST", let tail) where tail.count == 3 && tail[0] == "repositories" && tail[2] == "retry":
            return .retry(tail[1])
        case ("GET", ["share"]): return .share
        case ("PUT", ["share"]): return .updateShare
        default: return nil
        }
    }

    private func isKnownThread(_ id: String) -> Bool {
        [Self.rootID, Self.childID, "q7adapt0", "r3search", Self.createdChildID].contains(id)
    }

    private func isChild(_ id: String) -> Bool { id != Self.rootID }

    private func noteRequest(method: String, path: [String], body: Data) {
        let text = "\(method) \(path.joined(separator: "/"))\n" + String(decoding: body, as: UTF8.self)
        lock.withLock { state.lastRequest = text }
    }

    // MARK: Scenario answers

    private func scenarioReply(
        action: Action, id: String, scenario: String, request: StubRequest, context: StubContext
    ) -> StubReply {
        let isRecipient = scenario == "overview-recipient"
        if scenario == "overview-broken", action == .environment || action == .share {
            return error(500, "server_error", "Yak could not handle this request. Try again.")
        }
        if isRecipient, action != .stack, action != .environment {
            return error(403, "forbidden", "You do not have access to this.")
        }
        if scenario == "overview-busy", !action.isRead {
            let key = "\(action)-\(id)"
            let count = lock.withLock { () -> Int in
                let count = state.actionCounts[key, default: 0]
                state.actionCounts[key] = count + 1
                return count
            }
            if count < Self.busyAnswers {
                return error(
                    503, "unavailable", "Yak is still handling another request for this thread. Try again in a moment.")
            }
        }
        var reply = performAction(action: action, id: id, scenario: scenario, request: request, context: context)
        if scenario == "overview-slow", action == .stack || action == .environment { reply.delay = Self.slowDelay }
        return reply
    }

    private func performAction(
        action: Action, id: String, scenario: String, request: StubRequest, context: StubContext
    ) -> StubReply {
        let body = (try? JSONSerialization.jsonObject(with: request.body)) as? [String: Any] ?? [:]
        let fixtures = context.fixtures
        let baseURL = context.baseURL
        switch action {
        case .stack:
            return json(stack(id: id, scenario: scenario, fixtures: fixtures, baseURL: baseURL))
        case .addChild:
            if scenario == "overview-refusals" {
                return error(
                    409, "stack_full", "This thread has started 12 of 12 sub-threads, the most one thread can have.",
                    details: ["count": 12, "max": 12])
            }
            let sendKey = body["send_key"] as? String ?? ""
            let name = body["name"] as? String ?? "sub-thread"
            lock.withLock {
                if sendKey.isEmpty || state.sendKeys[sendKey] == nil {
                    state.sendKeys[sendKey] = name
                    state.addedChildren.append(Self.queuedChild(name: name))
                }
            }
            var created = fixtures.scenario("scenario-overview-sub-thread-created", baseURL: baseURL)
            created["name"] = lock.withLock { state.sendKeys[sendKey] } ?? name
            return json(created, status: 201)
        case .stop:
            lock.withLock { state.isStopped = true }
            return json(["status": "stopping"])
        case .limit:
            let max =
                (stack(id: id, scenario: scenario, fixtures: fixtures, baseURL: baseURL)["limit"] as? [String: Any])?[
                    "max"] as? Int ?? 4
            guard let value = body["limit"] as? Int, (1...max).contains(value) else {
                return error(
                    422, "validation_failed", "Choose between 1 and \(max).",
                    details: ["errors": ["limit": ["Choose between 1 and \(max)."]]])
            }
            lock.withLock { state.limit = value }
            var limit =
                (stack(id: id, scenario: scenario, fixtures: fixtures, baseURL: baseURL)["limit"] as? [String: Any])
                ?? [:]
            limit["current"] = value
            return json(["status": "updated", "limit": limit])
        case .takeOver, .split, .drop:
            if scenario == "overview-refusals" {
                return error(
                    409, "child_not_failed",
                    "Only a sub-thread that failed can be taken over, split or dropped.",
                    details: ["state": "working"])
            }
            lock.withLock {
                if action == .takeOver { state.takenOver.insert(id) }
                if action == .drop { state.dropped.insert(id) }
            }
            let status = action == .takeOver ? "taken_over" : action == .split ? "split_requested" : "dropped"
            return json(["status": status])
        case .environment:
            return json(environment(scenario: scenario, fixtures: fixtures, baseURL: baseURL))
        case .retry(let repositoryID):
            if scenario == "overview-refusals" || scenario == "overview-recipient" {
                return error(
                    409, "nothing_to_retry", "Only a failed setup of an extra repository can be retried.")
            }
            lock.withLock { state.retriedRepositories[repositoryID] = 0 }
            return json(["status": "retrying"])
        case .share:
            if scenario == "overview-recipient" { return error(403, "forbidden", "You do not have access to this.") }
            var share = fixtures.scenario("scenario-overview-share", baseURL: baseURL)
            if let sent = lock.withLock({ state.share }) {
                share["mode"] = sent.mode
                share["userIds"] = sent.userIDs
            }
            return json(share)
        case .updateShare:
            let mode = body["mode"] as? String ?? "only_me"
            let userIDs = (body["user_ids"] as? [Any] ?? []).compactMap { ($0 as? Int) ?? Int("\($0)") }
            lock.withLock { state.share = (mode, userIDs) }
            return json(["mode": mode, "userIds": userIDs])
        }
    }

    private func serverFixtureReply(action: Action, id: String, request: StubRequest, context: StubContext)
        -> StubReply
    {
        let fixtures = context.fixtures
        switch action {
        case .stack:
            return json(fixtures.serverFirst(isChild(id) ? "stack-child" : "stack", baseURL: context.baseURL))
        case .environment: return json(fixtures.serverFirst("thread-environment", baseURL: context.baseURL))
        case .share: return json(fixtures.serverFirst("thread-share", baseURL: context.baseURL))
        case .addChild: return json(["id": "stubchild", "name": "backfill-job-2"], status: 201)
        case .stop: return json(["status": "stopping"])
        case .limit: return json(["status": "updated", "limit": ["awake": 1, "current": 3, "max": 4, "waiting": 0]])
        case .takeOver: return json(["status": "taken_over"])
        case .split: return json(["status": "split_requested"])
        case .drop: return json(["status": "dropped"])
        case .retry: return json(["status": "retrying"])
        case .updateShare:
            let body = (try? JSONSerialization.jsonObject(with: request.body)) as? [String: Any] ?? [:]
            return json(["mode": body["mode"] ?? "only_me", "userIds": body["user_ids"] ?? []])
        }
    }

    // MARK: Bodies

    private static func queuedChild(name: String) -> [String: Any] {
        [
            "id": createdChildID, "name": name, "title": name, "kind": "implement", "model": "sonnet",
            "state": "queued", "step": NSNull(), "resultSummary": NSNull(), "branch": NSNull(), "repo": NSNull(),
            "pullRequestUrl": NSNull(), "ciStatus": NSNull(), "filesChanged": NSNull(), "queued": true,
            "messagesPaused": false, "takenOver": false, "canOverride": false,
        ]
    }

    private func stack(id: String, scenario: String, fixtures: StubFixtures, baseURL: String) -> [String: Any] {
        if isChild(id) {
            return fixtures.scenario("scenario-overview-child-view", baseURL: baseURL)
        }
        let snapshot = lock.withLock { state }
        let file: String
        switch scenario {
        case "overview-full": file = "scenario-overview-full-stack"
        case "overview-recipient": file = "scenario-overview-recipient-stack"
        case "overview-no-sub-threads", "overview-new-thread": file = "scenario-overview-no-sub-threads"
        default: file = snapshot.isStopped ? "scenario-overview-stopped-stack" : "scenario-overview-busy-stack"
        }
        var stack = fixtures.scenario(file, baseURL: baseURL)
        var children = (stack["children"] as? [[String: Any]] ?? []) + snapshot.addedChildren
        children = children.map { child in
            var child = child
            let childID = child["id"] as? String ?? ""
            if snapshot.takenOver.contains(childID) {
                child["takenOver"] = true
                child["canOverride"] = false
            }
            if snapshot.dropped.contains(childID) {
                child["state"] = "closed"
                child["canOverride"] = false
            }
            return child
        }
        stack["children"] = children
        if snapshot.isStopped {
            stack["canStop"] = false
            if stack["stoppedAt"] is NSNull || stack["stoppedAt"] == nil {
                stack["stoppedAt"] = "2026-11-15T11:50:00+00:00"
            }
        }
        if let limit = snapshot.limit {
            var value = stack["limit"] as? [String: Any] ?? [:]
            value["current"] = limit
            stack["limit"] = value
        }
        return stack
    }

    private func environment(scenario: String, fixtures: StubFixtures, baseURL: String) -> [String: Any] {
        let reads = lock.withLock { () -> Int in
            state.environmentReads += 1
            for key in state.retriedRepositories.keys { state.retriedRepositories[key]! += 1 }
            return state.environmentReads
        }
        let retried = lock.withLock { state.retriedRepositories }
        let file: String
        switch scenario {
        case "overview-setting-up": file = "scenario-overview-environment-setting-up"
        case "overview-new-thread": file = "scenario-overview-environment-new"
        case "overview-no-sub-threads", "overview-refusals": file = "scenario-overview-environment-setup-failed"
        default: file = "scenario-overview-environment"
        }
        var environment = fixtures.scenario(file, baseURL: baseURL)
        var repositories = environment["repositories"] as? [[String: Any]] ?? []
        repositories = repositories.map { repository in
            var repository = repository
            let id = "\(repository["id"] ?? "")"
            if scenario == "overview-setting-up", reads >= 3 {
                repository["setupStatus"] = "ready"
            }
            if let reads = retried[id], scenario != "overview-refusals" {
                repository["setupStatus"] = reads >= 2 ? "ready" : "pending"
                repository["setupError"] = NSNull()
                repository["setupOutput"] = NSNull()
                repository["canMakeContextOnly"] = false
            }
            return repository
        }
        environment["repositories"] = repositories
        return environment
    }

    private func show(id: String, scenario: String, context: StubContext) -> [String: Any] {
        let fixtures = context.fixtures
        var show = fixtures.scenario(
            isChild(id) ? "scenario-overview-child-show" : "scenario-overview-thread-show", baseURL: context.baseURL)
        let sent = lock.withLock { state.share }
        func update(_ key: String, _ change: (inout [String: Any]) -> Void) {
            var inner = show[key] as? [String: Any] ?? [:]
            change(&inner)
            show[key] = inner
        }
        if let sent {
            update("thread") {
                $0["visibility"] = sent.mode == "everyone" ? "team" : "private"
                $0["sharedWithCount"] = sent.mode == "people" ? sent.userIDs.count : 0
            }
        }
        switch scenario {
        case "overview-recipient":
            update("thread") {
                $0["isOwner"] = false
                $0["visibility"] = "private"
            }
            show["canDrive"] = false
            update("composer") { $0["state"] = "readonly" }
        case "overview-new-thread":
            update("thread") { $0["branch"] = NSNull() }
        case "overview-private":
            update("thread") {
                $0["visibility"] = "private"
                $0["sharedWithCount"] = 1
            }
        default:
            break
        }
        return show
    }

    // MARK: Replies

    private func json(_ object: Any, status: Int = 200) -> StubReply {
        let data = (try? JSONSerialization.data(withJSONObject: object)) ?? Data("{}".utf8)
        return StubReply(answer: .reply(status: status, contentType: "application/json", body: data))
    }

    private func error(_ status: Int, _ code: String, _ message: String, details: [String: Any] = [:]) -> StubReply {
        StubReply(answer: Scenario.error(status, code, message, details: details))
    }
}
