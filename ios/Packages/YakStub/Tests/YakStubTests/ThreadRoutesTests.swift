import Foundation
import Testing

@testable import YakStub

private var iosDirectory: URL {
    var directory = URL(fileURLWithPath: #filePath)
    while directory.lastPathComponent != "ios" { directory.deleteLastPathComponent() }
    return directory
}

private var scenariosDirectory: URL {
    iosDirectory.appendingPathComponent("Packages/YakKit/Tests/YakKitTests/Scenarios")
}

private func fixtures(_ directory: URL? = nil) throws -> StubFixtures {
    try StubFixtures(
        directory: directory ?? iosDirectory.appendingPathComponent("fixtures"), scenariosDirectory: scenariosDirectory)
}

private func reply(
    _ scenario: Scenario, _ method: String, _ path: String, body: String = "", threads: StubThreads = StubThreads(),
    fixtures: StubFixtures? = nil
) throws -> StubReply {
    let target = StubServer.parseTarget("/api/app/v1/\(path)")
    let request = StubRequest(method: method, path: target.path, query: target.query, body: Data(body.utf8))
    return scenario.reply(
        to: request, baseURL: "http://127.0.0.1:1", fixtures: try fixtures ?? StubTestSupport.fixtures(),
        threads: threads)
}

private enum StubTestSupport {
    static func fixtures() throws -> StubFixtures { try YakStubTests.fixtures() }
}

private func status(_ reply: StubReply) -> Int? {
    if case .reply(let status, _, _) = reply.answer { return status }
    return nil
}

private func object(_ reply: StubReply) throws -> [String: Any] {
    guard case .reply(_, _, let body) = reply.answer else { return [:] }
    return try #require(try JSONSerialization.jsonObject(with: body) as? [String: Any])
}

private func sequences(_ reply: StubReply) throws -> [Int] {
    (try object(reply)["events"] as? [[String: Any]] ?? []).compactMap { $0["sequence"] as? Int }
}

private func rowIDs(_ reply: StubReply, _ key: String) throws -> [String] {
    (try object(reply)[key] as? [[String: Any]] ?? []).compactMap { $0["id"] as? String }
}

@Suite struct ThreadRoutesTests {
    @Test func servesTheThreadShowForAListedRow() throws {
        let show = try object(reply(.paired, "GET", "threads/a1b2c3d4"))
        let thread = try #require(show["thread"] as? [String: Any])
        #expect(thread["id"] as? String == "a1b2c3d4")
        #expect(thread["visibility"] as? String == "private")
        let missing = try reply(.paired, "GET", "threads/zzzzzzzz")
        #expect(status(missing) == 404)
        #expect(try object(missing)["error"] as? String == "not_found")
    }

    @Test func eventsAnswerOnlyWhatIsPublished() throws {
        let threads = StubThreads()
        let before = try reply(.oneThreadWorking, "GET", "threads/d0e515ef/events?after=0&limit=500", threads: threads)
        #expect(try sequences(before) == [])
        #expect(try object(before)["latestSequence"] as? Int == 0)
        threads.publish("d0e515ef", through: 8)
        let after = try reply(.oneThreadWorking, "GET", "threads/d0e515ef/events?after=0&limit=500", threads: threads)
        #expect(try sequences(after) == [1, 2, 3, 4, 5, 8])
        #expect(try object(after)["latestSequence"] as? Int == 8)
        let page = try reply(.oneThreadWorking, "GET", "threads/d0e515ef/events?after=4&limit=1", threads: threads)
        #expect(try sequences(page) == [5])
        let show = try object(reply(.oneThreadWorking, "GET", "threads/d0e515ef", threads: threads))
        #expect((show["composer"] as? [String: Any])?["state"] as? String == "working")
        #expect(show["latestSequence"] as? Int == 8)
    }

    @Test func aQueuedSendAnswersQueued() throws {
        let threads = StubThreads()
        let answer = try reply(
            .paired, "POST", "threads/d0e515ef/messages", body: #"{"message":"Hi","delivery":"after_turn"}"#,
            threads: threads)
        #expect(try object(answer)["status"] as? String == "queued")
        #expect(threads.lastMessage.contains("after_turn"))
        let sent = try reply(.paired, "POST", "threads/d0e515ef/messages", body: #"{"message":"Hi"}"#)
        #expect(try object(sent)["status"] as? String == "sent")
    }

    @Test func aClearAnswersWithTheNewThread() throws {
        let answer = try object(
            reply(.paired, "POST", "threads/d0e515ef/messages", body: #"{"message":" /clear "}"#))
        #expect(answer["uuid"] is NSNull)
        #expect(answer["threadId"] as? String == "n3w7hr3d")
    }

    @Test func answeringClosesTheQuestion() throws {
        let threads = StubThreads()
        #expect(
            try object(reply(.questionOpen, "GET", "threads/d0e515ef", threads: threads))["question"] is [String: Any])
        #expect(
            try status(reply(.questionOpen, "POST", "threads/d0e515ef/clarification-answers", threads: threads)) == 200)
        #expect(try object(reply(.questionOpen, "GET", "threads/d0e515ef", threads: threads))["question"] is NSNull)
    }

    @Test func stopPublishesAnInterruptedTurn() throws {
        let threads = StubThreads()
        threads.publish("d0e515ef", through: 5)
        #expect(
            try object(reply(.oneThreadWorking, "POST", "threads/d0e515ef/stop", threads: threads))["status"] as? String
                == "stopping")
        let page = try object(reply(.oneThreadWorking, "GET", "threads/d0e515ef/events?after=5", threads: threads))
        let events = try #require(page["events"] as? [[String: Any]])
        #expect(events.count == 1)
        #expect(events[0]["sequence"] as? Int == 6)
        #expect(events[0]["type"] as? String == "turn")
        #expect((events[0]["data"] as? [String: Any])?["state"] as? String == "interrupted")
        threads.publish("d0e515ef", through: 14)
        #expect(threads.published("d0e515ef") == 6)
    }

    @Test(arguments: [
        "c10sed00", "readc0py", "c0mp7ter", "vsage000", "g7ard000", "rest0re0", "acc07nt0", "cred0000", "wa17ng00",
        "n0events",
    ])
    func everyThreadStateHasItsShow(id: String) throws {
        let show = try object(reply(.threadStates, "GET", "threads/\(id)"))
        let composer = (show["composer"] as? [String: Any])?["state"] as? String
        let notice = (show["notice"] as? [String: Any])?["kind"] as? String
        let thread = show["thread"] as? [String: Any]
        switch id {
        case "c10sed00":
            #expect(composer == "disabled_closed")
            #expect(thread?["status"] as? String == "closed")
        case "readc0py":
            #expect(composer == "readonly")
            #expect(show["canDrive"] as? Bool == false)
        case "c0mp7ter":
            #expect(composer == "disabled_computer")
            #expect(thread?["ownerSide"] as? String == "device")
        case "vsage000":
            #expect(composer == "idle")
            #expect(notice == "usage_limit")
        case "g7ard000":
            #expect(notice == "guard_turns")
            #expect((show["notice"] as? [String: Any])?["canContinue"] as? Bool == true)
        case "rest0re0":
            #expect(composer == "disabled_blocked")
            #expect(notice == "restore_failed")
        case "acc07nt0":
            #expect(composer == "disabled_blocked")
            #expect(notice == "account_invalid")
        case "cred0000":
            #expect(composer == "disabled_blocked")
            #expect(notice == "credential_exposure")
        case "n0events":
            #expect(composer == "idle")
            #expect(notice == nil)
            #expect((show["messages"] as? [Any])?.isEmpty == true)
            let page = try object(reply(.threadStates, "GET", "threads/n0events/events?after=0"))
            #expect((page["events"] as? [Any])?.isEmpty == true)
        default:
            #expect(composer == "idle")
            #expect(notice == "waiting_for_sandbox")
        }
        #expect(thread?["id"] as? String == id)
    }

    @Test func continueClearsTheNotice() throws {
        let threads = StubThreads()
        #expect(
            try object(reply(.threadStates, "GET", "threads/g7ard000", threads: threads))["notice"] is [String: Any])
        _ = try reply(.threadStates, "POST", "threads/g7ard000/continue", threads: threads)
        #expect(try object(reply(.threadStates, "GET", "threads/g7ard000", threads: threads))["notice"] is NSNull)
    }

    @Test func offlineDropsEveryAPIRequest() async throws {
        let server = try StubServer(port: 0, fixtures: try fixtures(), scenario: .paired)
        let port = try await server.start()
        func get(_ path: String) async throws -> String {
            let (data, _) = try await URLSession.shared.data(from: URL(string: "http://127.0.0.1:\(port)\(path)")!)
            return String(decoding: data, as: UTF8.self)
        }
        var offline = URLRequest(url: URL(string: "http://127.0.0.1:\(port)/__stub/online")!)
        offline.httpMethod = "POST"
        offline.httpBody = Data("no".utf8)
        _ = try await URLSession.shared.data(for: offline)
        await #expect(throws: (any Error).self) { try await get("/api/app/v1/threads") }
        #expect(try await get("/__stub/online") == "no")
    }

    @Test func namedScenariosNeverReadServerFixtures() throws {
        let directory = try serverFixtureDirectory()
        let answer = try reply(.paired, "GET", "threads", fixtures: fixtures(directory))
        #expect(try rowIDs(answer, "threads") == ["a1b2c3d4"])
        #expect(try (object(answer)["needsYou"] as? [[String: Any]])?.first?["id"] as? String == "d0e515ef")
    }

    @Test func serverFixturesWinInTheirScenario() throws {
        let directory = try serverFixtureDirectory()
        let answer = try reply(.serverFixtures, "GET", "threads", fixtures: fixtures(directory))
        #expect(try rowIDs(answer, "threads") == ["zzzzzzzz"])
        let empty = FileManager.default.temporaryDirectory.appendingPathComponent(UUID().uuidString)
        try FileManager.default.createDirectory(at: empty, withIntermediateDirectories: true)
        for name in ["instance", "me", "device-code", "device-pair", "device-token"] {
            try FileManager.default.copyItem(
                at: iosDirectory.appendingPathComponent("fixtures/\(name).json"),
                to: empty.appendingPathComponent("\(name).json"))
        }
        let fallback = try reply(.serverFixtures, "GET", "threads", fixtures: fixtures(empty))
        #expect(try rowIDs(fallback, "threads") == ["a1b2c3d4"])
    }

    @Test func everyAgreedNameHasScenarioData() throws {
        #expect(StubFixtures.coreNames.count == 15)
        let loaded = try fixtures()
        for pair in StubFixtures.names {
            #expect(loaded.scenarioFiles[pair.scenario] != nil, "\(pair.scenario)")
            #expect(!loaded.scenario(pair.scenario).isEmpty, "\(pair.scenario)")
        }
    }

    @Test func searchFiltersByTitle() throws {
        let billing = try reply(.paired, "GET", "threads?filter=mine&search=billing")
        #expect(try rowIDs(billing, "threads") == ["a1b2c3d4"])
        #expect(try (object(billing)["needsYou"] as? [Any])?.isEmpty == true)
        let nothing = try reply(.paired, "GET", "threads?search=nothing")
        #expect(try rowIDs(nothing, "threads").isEmpty)
        #expect(try (object(nothing)["needsYou"] as? [Any])?.isEmpty == true)
    }

    @Test func teamOnlyAnswersPerFilter() throws {
        let mine = try object(reply(.teamOnly, "GET", "threads?filter=mine"))
        #expect(mine["hasAnyThreads"] as? Bool == true)
        #expect((mine["threads"] as? [Any])?.isEmpty == true)
        #expect(try rowIDs(reply(.teamOnly, "GET", "threads?filter=everyone"), "threads") == ["a1b2c3d4"])
        #expect(try object(reply(.empty, "GET", "threads"))["hasAnyThreads"] as? Bool == false)
    }

    @Test func refusalsAndRepositoryAccess() throws {
        #expect(try status(reply(.threadMissing, "GET", "threads/d0e515ef")) == 404)
        #expect(
            try object(reply(.messageRefused, "POST", "threads/d0e515ef/messages", body: "{}"))["error"] as? String
                == "thread_closed")
        let refused = try object(reply(.newThreadRefused, "POST", "threads", body: "{}"))
        #expect(refused["error"] as? String == "validation_failed")
        #expect(try object(reply(.paired, "POST", "threads", body: "{}"))["id"] as? String == "k7m2q9rt")
        let access = try object(reply(.paired, "GET", "threads/new/repository-access?repo=example/secret"))
        #expect(access["allowed"] as? Bool == false)
        #expect(
            try object(reply(.paired, "GET", "threads/new/repository-access?repo=example/api"))["allowed"] as? Bool
                == true)
        #expect(try reply(.slowThread, "GET", "threads/d0e515ef").delay == .seconds(14))
        #expect(try reply(.slowThread, "GET", "threads").delay == .zero)
    }

    @Test func longHistoryHas1200Events() throws {
        let page = try reply(.longHistory, "GET", "threads/d0e515ef/events?after=1100&limit=500")
        #expect(try sequences(page).count == 100)
        #expect(try object(page)["latestSequence"] as? Int == 1200)
    }

    @Test func onlyTheOlderPageOfALongHistoryIsSlow() throws {
        #expect(try reply(.longHistory, "GET", "threads/d0e515ef/events?after=200&limit=500").delay == .seconds(6))
        #expect(try reply(.longHistory, "GET", "threads/d0e515ef/events?after=700&limit=500").delay == .zero)
        #expect(try reply(.paired, "GET", "threads/d0e515ef/events?after=0&limit=500").delay == .zero)
    }

    @Test func authorizationAnswersPerChannelKind() throws {
        let presence = try object(
            reply(.paired, "POST", "broadcasting/auth", body: #"{"socket_id":"1.2","channel_name":"presence-x"}"#))
        let priv = try object(
            reply(.paired, "POST", "broadcasting/auth", body: #"{"socket_id":"1.2","channel_name":"private-x"}"#))
        #expect(presence["channel_data"] != nil)
        #expect(priv["channel_data"] == nil)
    }

    private func serverFixtureDirectory() throws -> URL {
        let directory = FileManager.default.temporaryDirectory.appendingPathComponent(UUID().uuidString)
        try FileManager.default.createDirectory(at: directory, withIntermediateDirectories: true)
        for name in ["instance", "me", "device-code", "device-pair", "device-token"] {
            try FileManager.default.copyItem(
                at: iosDirectory.appendingPathComponent("fixtures/\(name).json"),
                to: directory.appendingPathComponent("\(name).json"))
        }
        let row =
            #"{"id":"zzzzzzzz","title":"Server row","repo":"example/api","status":"running","needsYouReason":null,"needsYouLabel":null,"isPrivate":false,"isShared":false,"isUnattended":false,"isStarred":false,"canDelete":true,"ownerInitials":"TP","lastActivityAt":null,"group":"today","children":[]}"#
        try Data(#"{"starred":[],"needsYou":[],"threads":[\#(row)],"needsYouCount":0,"hasAnyThreads":true}"#.utf8)
            .write(to: directory.appendingPathComponent("threads.json"))
        return directory
    }
}
