import Foundation

/// What the stub remembers about threads between requests: how far each event list is published, whether a
/// question was answered, whether a turn was stopped, and the last message body. A new scenario resets it.
final class StubThreads: @unchecked Sendable {
    private let lock = NSLock()
    private var publishedCounts: [String: Int] = [:]
    private var answeredThreads: Set<String> = []
    private var stoppedThreads: Set<String> = []
    private var clearedNotices: Set<String> = []
    private var interruptions: [String: [[String: Any]]] = [:]
    private var lastBody = ""

    func reset() {
        lock.withLock {
            publishedCounts = [:]
            answeredThreads = []
            stoppedThreads = []
            clearedNotices = []
            interruptions = [:]
            lastBody = ""
        }
    }

    /// Publishes the stored events up to `sequence`. A stopped thread publishes nothing more; the count never drops.
    func publish(_ id: String, through sequence: Int) {
        lock.withLock {
            guard !stoppedThreads.contains(id) else { return }
            publishedCounts[id] = max(publishedCounts[id] ?? 0, sequence)
        }
    }

    func published(_ id: String) -> Int? { lock.withLock { publishedCounts[id] } }
    func isAnswered(_ id: String) -> Bool { lock.withLock { answeredThreads.contains(id) } }
    func markAnswered(_ id: String) { lock.withLock { _ = answeredThreads.insert(id) } }
    func isStopped(_ id: String) -> Bool { lock.withLock { stoppedThreads.contains(id) } }
    func isNoticeCleared(_ id: String) -> Bool { lock.withLock { clearedNotices.contains(id) } }
    func clearNotice(_ id: String) { lock.withLock { _ = clearedNotices.insert(id) } }
    func extraEvents(_ id: String) -> [[String: Any]] { lock.withLock { interruptions[id] ?? [] } }

    var lastMessage: String {
        get { lock.withLock { lastBody } }
        set { lock.withLock { lastBody = newValue } }
    }

    /// Stops playback and publishes one more event after `current`.
    func interrupt(_ id: String, after current: Int) {
        lock.withLock {
            stoppedThreads.insert(id)
            let sequence = current + 1
            interruptions[id, default: []].append([
                "sequence": sequence, "type": "turn",
                "data": ["turnId": 41, "state": "interrupted"],
                "createdAt": ISO8601DateFormatter().string(from: Date()),
            ])
            publishedCounts[id] = sequence
        }
    }
}

private let statesOfThreads: [(id: String, title: String)] = [
    ("c10sed00", "A closed thread"),
    ("readc0py", "A thread you can read"),
    ("c0mp7ter", "A thread on a computer"),
    ("vsage000", "A thread at its usage limit"),
    ("g7ard000", "A thread stopped by the guard"),
    ("rest0re0", "A thread whose restore failed"),
    ("acc07nt0", "A thread with an account problem"),
    ("cred0000", "A thread with an exposed credential"),
    ("wa17ng00", "A thread waiting for a sandbox"),
]

private func update(_ dictionary: inout [String: Any], _ key: String, _ change: (inout [String: Any]) -> Void) {
    var inner = dictionary[key] as? [String: Any] ?? [:]
    change(&inner)
    dictionary[key] = inner
}

private func rows(in list: [String: Any]) -> [[String: Any]] {
    func flatten(_ row: [String: Any]) -> [[String: Any]] {
        [row] + (row["children"] as? [[String: Any]] ?? []).flatMap(flatten)
    }
    let needy = (list["needsYou"] as? [[String: Any]] ?? []).filter { $0["type"] as? String == "thread" }
    return ((list["starred"] as? [[String: Any]] ?? []) + needy + (list["threads"] as? [[String: Any]] ?? []))
        .flatMap(flatten)
}

private func json(_ object: Any, status: Int = 200) -> StubAnswer {
    let data = (try? JSONSerialization.data(withJSONObject: object)) ?? Data("{}".utf8)
    return .reply(status: status, contentType: "application/json", body: data)
}

private let noContent = StubAnswer.reply(status: 204, contentType: "application/json", body: Data())

extension Scenario {
    var playsLive: Bool { self == .oneThreadWorking }

    /// The data file of the scenario, or the server's own fixture in the `server-fixtures` scenario.
    private func data(_ name: String, _ fixtures: StubFixtures, _ baseURL: String) -> [String: Any] {
        self == .serverFixtures
            ? fixtures.serverFirst(name, baseURL: baseURL) : fixtures.scenario(name, baseURL: baseURL)
    }

    /// The thread list this scenario answers to `filter`, before a search narrows it.
    private func list(filter: String, _ fixtures: StubFixtures, _ baseURL: String) -> [String: Any] {
        var list = data("scenario-thread-list", fixtures, baseURL)
        let needy = list["needsYou"] as? [[String: Any]] ?? []
        func emptied(hasAnyThreads: Bool) {
            list["starred"] = [[String: Any]]()
            list["needsYou"] = [[String: Any]]()
            list["threads"] = [[String: Any]]()
            list["needsYouCount"] = 0
            list["hasAnyThreads"] = hasAnyThreads
        }
        switch self {
        case .questionOpen:
            list["needsYou"] = needy.filter { $0["id"] as? String == "d0e515ef" }
            list["threads"] = [[String: Any]]()
            list["needsYouCount"] = 1
        case .oneThreadWorking:
            var row = needy.first { $0["id"] as? String == "d0e515ef" } ?? [:]
            row["needsYouReason"] = NSNull()
            row["needsYouLabel"] = NSNull()
            list["needsYou"] = [[String: Any]]()
            list["threads"] = [row]
            list["needsYouCount"] = 0
        case .teamOnly:
            if filter != "everyone" { emptied(hasAnyThreads: true) }
        case .empty, .noRepositories, .slowNewThread, .brokenNewThread:
            emptied(hasAnyThreads: false)
        case .threadStates:
            let template =
                (list["threads"] as? [[String: Any]])?.first.map { row -> [String: Any] in
                    var copy = row
                    copy["children"] = [[String: Any]]()
                    return copy
                } ?? [:]
            list["needsYou"] = [[String: Any]]()
            list["needsYouCount"] = 0
            list["threads"] = statesOfThreads.map { state -> [String: Any] in
                var row = template
                row["id"] = state.id
                row["title"] = state.title
                row["status"] = state.id == "c10sed00" ? "closed" : "stopped"
                return row
            }
        default:
            break
        }
        return list
    }

    private func narrowed(_ list: [String: Any], by search: String) -> [String: Any] {
        let needle = search.trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
        guard !needle.isEmpty else { return list }
        func matches(_ row: [String: Any]) -> Bool {
            (row["title"] as? String ?? "").lowercased().contains(needle)
                || (row["children"] as? [[String: Any]] ?? []).contains(where: matches)
        }
        var result = list
        for key in ["starred", "needsYou", "threads"] {
            result[key] = (list[key] as? [[String: Any]] ?? []).filter(matches)
        }
        return result
    }

    /// The stored events of the thread: the list's broadcasts without deltas and resets, a pointer replaced by
    /// the event it points at.
    private func storedEvents(_ id: String, _ fixtures: StubFixtures, _ baseURL: String) -> [[String: Any]] {
        if self == .longHistory, id == "d0e515ef" {
            return (1...1200).map { number in
                [
                    "sequence": number, "type": "entry",
                    "data": [
                        "role": "assistant", "messageId": "msg_\(number)",
                        "blocks": [["type": "text", "text": "Step \(number).", "html": "<p>Step \(number).</p>"]],
                    ] as [String: Any],
                    "createdAt": "2026-11-15T09:00:00Z",
                ]
            }
        }
        let name = self == .questionOpen ? "events/question" : "events/simple-turn"
        let source =
            self == .serverFixtures
            ? fixtures.serverFirst(name, baseURL: baseURL) : fixtures.scenario(name, baseURL: baseURL)
        var pointed: [Int: [String: Any]] = [:]
        for item in source["pointers"] as? [[String: Any]] ?? [] {
            if let sequence = item["sequence"] as? Int, let event = item["event"] as? [String: Any] {
                pointed[sequence] = event
            }
        }
        return (source["broadcasts"] as? [[String: Any]] ?? []).compactMap { broadcast in
            if broadcast["pointer"] as? Bool == true, let sequence = broadcast["sequence"] as? Int {
                return pointed[sequence]
            }
            let type = broadcast["type"] as? String
            return type == "delta" || type == "stream.reset" ? nil : broadcast
        }
    }

    /// The items the socket plays for a thread, in order, each with its sequence and its JSON text.
    func broadcasts(_ id: String, _ fixtures: StubFixtures, _ baseURL: String) -> [(sequence: Int, json: String)] {
        let source = data("events/simple-turn", fixtures, baseURL)
        return (source["broadcasts"] as? [[String: Any]] ?? []).compactMap { item in
            guard let sequence = item["sequence"] as? Int,
                let data = try? JSONSerialization.data(withJSONObject: item, options: [.sortedKeys])
            else { return nil }
            return (sequence, String(decoding: data, as: UTF8.self))
        }
    }

    private func events(_ id: String, _ fixtures: StubFixtures, _ baseURL: String, _ threads: StubThreads)
        -> (events: [[String: Any]], published: Int)
    {
        let stored = storedEvents(id, fixtures, baseURL)
        let total = stored.compactMap { $0["sequence"] as? Int }.max() ?? 0
        let published = threads.published(id) ?? (playsLive ? 0 : total)
        let all = (stored + threads.extraEvents(id)).filter { ($0["sequence"] as? Int ?? .max) <= published }
        return (all.sorted { ($0["sequence"] as? Int ?? 0) < ($1["sequence"] as? Int ?? 0) }, published)
    }

    private func show(
        row: [String: Any], _ fixtures: StubFixtures, _ baseURL: String, _ threads: StubThreads
    ) -> [String: Any] {
        let id = row["id"] as? String ?? ""
        let asksQuestion = self == .questionOpen && id == "d0e515ef" && !threads.isAnswered(id)
        var show = data(asksQuestion ? "scenario-thread-question" : "scenario-thread-show", fixtures, baseURL)
        let published = events(id, fixtures, baseURL, threads).published
        let total = storedEvents(id, fixtures, baseURL).compactMap { $0["sequence"] as? Int }.max() ?? 0
        show["latestSequence"] = published
        if self == .serverFixtures { return show }
        update(&show, "thread") {
            $0["id"] = id
            $0["title"] = row["title"]
            $0["repo"] = row["repo"] ?? $0["repo"]
            $0["visibility"] = row["isPrivate"] as? Bool == true ? "private" : "team"
        }
        if playsLive, published < total, !threads.isStopped(id) {
            update(&show, "composer") { $0["state"] = "working" }
        }
        if !asksQuestion { show["question"] = NSNull() }
        guard self == .threadStates else { return show }
        func state(_ composer: String) { update(&show, "composer") { $0["state"] = composer } }
        func notice(_ kind: String, _ title: String, _ body: String, resetsAt: String? = nil, canContinue: Bool = false)
        {
            guard !threads.isNoticeCleared(id) else { return }
            show["notice"] = [
                "kind": kind, "title": title, "body": body, "resetsAt": resetsAt as Any? ?? NSNull(),
                "canContinue": canContinue,
            ]
        }
        switch id {
        case "c10sed00":
            update(&show, "thread") { $0["status"] = "closed" }
            state("disabled_closed")
        case "readc0py":
            show["canDrive"] = false
            state("readonly")
        case "c0mp7ter":
            update(&show, "thread") { $0["ownerSide"] = "device" }
            state("disabled_computer")
        case "vsage000":
            notice(
                "usage_limit", "Usage limit reached", "This account used all of its allowance for now.",
                resetsAt: ISO8601DateFormatter().string(from: Date().addingTimeInterval(7200)))
        case "g7ard000":
            notice(
                "guard_turns", "Claude stopped after 50 steps", "Continue to let Claude keep working.",
                canContinue: true)
        case "rest0re0":
            state("disabled_blocked")
            notice("restore_failed", "Rebuilding the sandbox stopped at step 2", "Retry to rebuild it.")
        case "acc07nt0":
            state("disabled_blocked")
            notice("account_invalid", "The account needs attention", "Choose another account to go on.")
        case "cred0000":
            state("disabled_blocked")
            notice("credential_exposure", "A credential was exposed", "Rotate it, then start a new thread.")
        case "wa17ng00":
            notice("waiting_for_sandbox", "Waiting for a free sandbox", "The thread starts when one is free.")
        default:
            break
        }
        return show
    }

    /// Answers the thread routes, or `nil` when `route` is not one of them.
    func threadReply(
        route: String, request: StubRequest, baseURL: String, fixtures: StubFixtures, threads: StubThreads
    ) -> StubReply? {
        let parts = route.split(separator: " ", maxSplits: 1).map(String.init)
        guard parts.count == 2 else { return nil }
        let method = parts[0]
        let path = parts[1].split(separator: "/").map(String.init)
        guard path.first == "threads" || path == ["broadcasting", "auth"] else { return nil }
        let body = (try? JSONSerialization.jsonObject(with: request.body)) as? [String: Any] ?? [:]
        let listed = rows(in: list(filter: "everyone", fixtures, baseURL))
        func row(_ id: String) -> [String: Any]? { listed.first { $0["id"] as? String == id } }
        func notFound() -> StubAnswer { Self.error(404, "not_found", "This thread does not exist.") }
        func answer() -> StubAnswer {
            switch (method, path.count, path.dropFirst().first ?? "", path.last ?? "") {
            case ("POST", 2, "auth", _):
                let channel = body["channel_name"] as? String ?? String(decoding: request.body, as: UTF8.self)
                let name = channel.contains("presence-") ? "presence" : "private"
                return json(fixtures.scenario("scenario-broadcasting-auth-\(name)", baseURL: baseURL))
            case ("GET", 1, _, _):
                let filter = request.query["filter"] ?? "mine"
                return json(narrowed(list(filter: filter, fixtures, baseURL), by: request.query["search"] ?? ""))
            case ("POST", 1, _, _):
                if self == .newThreadRefused {
                    return Self.error(
                        422, "validation_failed", "The description is too long.",
                        details: ["errors": ["description": ["The description is too long."]]])
                }
                return json(data("scenario-thread-store", fixtures, baseURL), status: 201)
            case ("GET", 2, "new", _):
                if self == .brokenNewThread {
                    return Self.error(500, "server_error", "Yak could not handle this request. Try again.")
                }
                var options = data("scenario-thread-new", fixtures, baseURL)
                if self == .noRepositories {
                    options["repos"] = [[String: Any]]()
                    options["defaultRepo"] = NSNull()
                    if var picker = options["picker"] as? [String: Any] {
                        picker["defaultsByRepo"] = [String: Any]()
                        options["picker"] = picker
                    }
                }
                return json(options)
            case ("GET", 3, "new", "repository-access"):
                var access = data("scenario-thread-repository-access", fixtures, baseURL)
                if request.query["repo"] == "example/secret" {
                    access = ["allowed": false, "reason": "You cannot use this repository."]
                }
                return json(access)
            default:
                break
            }
            guard path.count >= 2, path[1] != "new" else { return Self.error(404, "not_found", "Not found.") }
            let id = path[1]
            let rest = Array(path.dropFirst(2))
            switch (method, rest) {
            case ("GET", []):
                guard let found = row(id), !(self == .threadMissing && id == "d0e515ef") else { return notFound() }
                return json(show(row: found, fixtures, baseURL, threads))
            case ("POST", ["seen"]):
                return noContent
            case ("GET", ["events"]):
                guard row(id) != nil else { return notFound() }
                let (all, published) = events(id, fixtures, baseURL, threads)
                let after = Int(request.query["after"] ?? "") ?? 0
                let limit = max(Int(request.query["limit"] ?? "") ?? 500, 1)
                let page = all.filter { ($0["sequence"] as? Int ?? 0) > after }.prefix(limit)
                return json(["events": Array(page), "latestSequence": published, "source": "database"])
            case ("GET", let tail) where tail.count == 2 && tail[0] == "events":
                let found = events(id, fixtures, baseURL, threads).events.first {
                    String($0["sequence"] as? Int ?? -1) == tail[1]
                }
                return found.map { json($0) } ?? notFound()
            case ("POST", ["messages"]):
                if self == .messageRefused { return Self.error(409, "thread_closed", "This thread is closed.") }
                threads.lastMessage = String(decoding: request.body, as: UTF8.self)
                let text = (body["message"] ?? body["text"]) as? String ?? ""
                if text.trimmingCharacters(in: .whitespacesAndNewlines) == "/clear" {
                    var cleared = data("scenario-message-clear", fixtures, baseURL)
                    cleared["threadId"] = "n3w7hr3d"
                    return json(cleared)
                }
                let queued = String(decoding: request.body, as: UTF8.self).contains("after_turn")
                return json(data(queued ? "scenario-message-queued" : "scenario-message-sent", fixtures, baseURL))
            case ("PATCH", let tail) where tail.count == 2 && tail[0] == "queued-messages":
                return json(["status": "updated"])
            case ("DELETE", let tail) where tail.count == 2 && tail[0] == "queued-messages":
                return noContent
            case ("POST", ["clarification-answers"]):
                threads.markAnswered(id)
                return json(["status": "sent"])
            case ("POST", ["stop"]):
                threads.interrupt(id, after: events(id, fixtures, baseURL, threads).published)
                return json(["status": "stopping"])
            case ("POST", ["continue"]):
                threads.clearNotice(id)
                return json(["status": "continued"])
            case ("POST", ["restore", "retry"]):
                threads.clearNotice(id)
                return json(["status": "restoring"])
            default:
                return Self.error(404, "not_found", "Not found.")
            }
        }
        var reply = StubReply(answer: answer())
        if self == .slowThread, method == "GET", path.count == 2, path[1] != "new" { reply.delay = .seconds(14) }
        if self == .slowNewThread, method == "GET", path == ["threads", "new"] { reply.delay = .seconds(14) }
        return reply
    }
}
