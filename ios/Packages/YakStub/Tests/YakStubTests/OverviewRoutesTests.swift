import Foundation
import Testing

@testable import YakStub

private var iosDirectory: URL {
    var directory = URL(fileURLWithPath: #filePath)
    while directory.lastPathComponent != "ios" { directory.deleteLastPathComponent() }
    return directory
}

private func makeFixtures(directory: URL? = nil) throws -> StubFixtures {
    try StubFixtures(
        directory: directory ?? iosDirectory.appendingPathComponent("fixtures"),
        scenariosDirectory: iosDirectory.appendingPathComponent("Packages/YakKit/Tests/YakKitTests/Scenarios"))
}

private struct Played {
    let overview = StubOverview()
    let fixtures: StubFixtures
    let scenario: String
    let threads = StubThreads()

    init(_ scenario: String, fixtures: StubFixtures? = nil) throws {
        self.scenario = scenario
        self.fixtures = try fixtures ?? makeFixtures()
    }

    func call(_ method: String, _ route: String, _ body: [String: Any]? = nil) throws -> (Int, [String: Any]) {
        let data = try body.map { try JSONSerialization.data(withJSONObject: $0) } ?? Data()
        let request = StubRequest(method: method, path: Scenario.apiPrefix + route, body: data)
        let context = StubContext(
            scenario: scenario, baseURL: "http://127.0.0.1", fixtures: fixtures, threads: threads,
            core: { .drop })
        guard let reply = overview.answer(route: "\(method) \(route)", request: request, context: context),
            case .reply(let status, _, let payload) = reply.answer
        else { return (-1, [:]) }
        return (status, (try? JSONSerialization.jsonObject(with: payload)) as? [String: Any] ?? [:])
    }

    func children(_ id: String = "s7ackr00") throws -> [[String: Any]] {
        try call("GET", "threads/\(id)/stack").1["children"] as? [[String: Any]] ?? []
    }

    func repositoryStatus(_ id: Int) throws -> String? {
        let repositories = try call("GET", "threads/s7ackr00/environment").1["repositories"] as? [[String: Any]]
        return repositories?.first { $0["id"] as? Int == id }?["setupStatus"] as? String
    }

    var lastRequest: String {
        let reply = overview.control(StubRequest(method: "GET", path: "/__stub/overview/last-request"))
        guard case .reply(_, _, let body)? = reply?.answer else { return "" }
        return String(decoding: body, as: UTF8.self)
    }
}

@Suite struct OverviewRoutesTests {
    @Test func servesTheStackAndTheChildView() throws {
        let played = try Played("overview-stack")
        let root = try played.call("GET", "threads/s7ackr00/stack").1
        #expect((root["children"] as? [Any])?.count == 3)
        let child = try played.call("GET", "threads/b4ckf111/stack").1
        #expect(child["isRoot"] as? Bool == false)
    }

    @Test func anAddIsSafeToRepeat() throws {
        let played = try Played("overview-stack")
        let body: [String: Any] = ["name": "check-the-cache", "brief": "Look.", "kind": "research", "send_key": "K"]
        let first = try played.call("POST", "threads/s7ackr00/stack/children", body)
        let second = try played.call("POST", "threads/s7ackr00/stack/children", body)
        #expect(first.0 == 201 && second.0 == 201)
        #expect(first.1["id"] as? String == "n3wch11d" && second.1["id"] as? String == "n3wch11d")
        #expect(try played.children().count == 4)
        #expect(played.lastRequest.hasPrefix("POST threads/s7ackr00/stack/children\n"))
        #expect(played.lastRequest.contains("check-the-cache"))
    }

    @Test func stopAndActionsChangeWhatTheStackSays() throws {
        let played = try Played("overview-stack")
        _ = try played.call("POST", "threads/s7ackr00/stack/stop")
        #expect(try played.call("GET", "threads/s7ackr00/stack").1["stoppedAt"] is String)
        let taken = try Played("overview-stack")
        _ = try taken.call("POST", "threads/q7adapt0/stack/take-over")
        let child = try #require(taken.children().first { $0["id"] as? String == "q7adapt0" })
        #expect(child["takenOver"] as? Bool == true && child["canOverride"] as? Bool == false)
        _ = try taken.call("POST", "threads/q7adapt0/stack/drop")
        #expect(try taken.children().first { $0["id"] as? String == "q7adapt0" }?["state"] as? String == "closed")
    }

    @Test func aLimitOutOfRangeIsRefused() throws {
        let played = try Played("overview-stack")
        let refused = try played.call("PATCH", "threads/s7ackr00/stack/limit", ["limit": 9])
        #expect(refused.0 == 422)
        let errors = (refused.1["details"] as? [String: Any])?["errors"] as? [String: Any]
        #expect(errors?["limit"] as? [String] == ["Choose between 1 and 4."])
        let accepted = try played.call("PATCH", "threads/s7ackr00/stack/limit", ["limit": 3])
        #expect(accepted.0 == 200 && (accepted.1["limit"] as? [String: Any])?["current"] as? Int == 3)
    }

    @Test func retryTurnsReadyOnTheSecondRead() throws {
        let played = try Played("overview-no-sub-threads")
        #expect(try played.repositoryStatus(22) == "failed")
        #expect(try played.call("POST", "threads/s7ackr00/repositories/22/retry").1["status"] as? String == "retrying")
        #expect(try played.repositoryStatus(22) == "pending")
        #expect(try played.repositoryStatus(22) == "ready")
    }

    @Test func aNewThreadHasNoLinks() throws {
        let played = try Played("overview-new-thread")
        let environment = try played.call("GET", "threads/s7ackr00/environment").1
        #expect(environment["branch"] is NSNull && environment["pullRequest"] is NSNull)
        #expect((environment["repositories"] as? [Any])?.count == 1)
        #expect(try played.children().isEmpty)
        #expect((try played.call("GET", "threads/s7ackr00").1["thread"] as? [String: Any])?["branch"] is NSNull)
    }

    @Test func aSettingUpRepositoryIsReadyFromTheThirdRead() throws {
        let played = try Played("overview-setting-up")
        #expect(try played.repositoryStatus(22) == "cloning")
        #expect(try played.repositoryStatus(22) == "cloning")
        #expect(try played.repositoryStatus(22) == "ready")
    }

    @Test func aRecipientIsRefusedEverywhere() throws {
        let played = try Played("overview-recipient")
        for (method, route) in [
            ("GET", "share"), ("POST", "stack/stop"), ("POST", "stack/children"), ("PUT", "share"),
            ("POST", "repositories/22/retry"), ("PATCH", "stack/limit"),
        ] {
            let result = try played.call(method, "threads/s7ackr00/\(route)")
            #expect(result.0 == 403 && result.1["error"] as? String == "forbidden", "\(method) \(route)")
        }
        let show = try played.call("GET", "threads/s7ackr00").1
        #expect((show["thread"] as? [String: Any])?["isOwner"] as? Bool == false)
        #expect((show["composer"] as? [String: Any])?["state"] as? String == "readonly")
    }

    @Test func refusalsAnswerTheServersSentences() throws {
        let played = try Played("overview-refusals")
        let add = try played.call("POST", "threads/s7ackr00/stack/children", ["send_key": "K"])
        #expect(add.0 == 409 && add.1["error"] as? String == "stack_full")
        let drop = try played.call("POST", "threads/q7adapt0/stack/drop")
        #expect(drop.0 == 409 && drop.1["error"] as? String == "child_not_failed")
        #expect(
            try played.call("POST", "threads/s7ackr00/repositories/22/retry").1["error"] as? String
                == "nothing_to_retry")
        #expect(try played.repositoryStatus(22) == "failed")
    }

    @Test func busyAnswersTwiceThenWorks() throws {
        let played = try Played("overview-busy")
        let statuses = try (0..<3).map { _ in try played.call("POST", "threads/q7adapt0/stack/take-over").0 }
        #expect(statuses == [503, 503, 200])
    }

    @Test func brokenFailsEnvironmentAndShareOnly() throws {
        let played = try Played("overview-broken")
        #expect(try played.call("GET", "threads/s7ackr00/environment").0 == 500)
        #expect(try played.call("GET", "threads/s7ackr00/share").0 == 500)
        #expect(try played.call("GET", "threads/s7ackr00/stack").0 == 200)
    }

    @Test func sharingFollowsTheSentMode() throws {
        let played = try Played("overview-stack")
        _ = try played.call("PUT", "threads/s7ackr00/share", ["mode": "everyone", "user_ids": [Int]()])
        #expect(
            (try played.call("GET", "threads/s7ackr00").1["thread"] as? [String: Any])?["visibility"] as? String
                == "team")
        _ = try played.call("PUT", "threads/s7ackr00/share", ["mode": "people", "user_ids": [7, 8]])
        let thread = try played.call("GET", "threads/s7ackr00").1["thread"] as? [String: Any]
        #expect(thread?["sharedWithCount"] as? Int == 2)
        #expect(try played.call("GET", "threads/s7ackr00/share").1["userIds"] as? [Int] == [7, 8])
    }

    @Test func overviewScenariosNeverReadServerFixtures() throws {
        let directory = try serverFixturesWithOneChild()
        let played = try Played("overview-stack", fixtures: makeFixtures(directory: directory))
        #expect(try played.children().contains { $0["id"] as? String == "b4ckf111" })
    }

    @Test func serverFixturesWinInTheirScenario() throws {
        let directory = try serverFixturesWithOneChild()
        let played = try Played("server-fixtures", fixtures: makeFixtures(directory: directory))
        #expect(try played.children().map { $0["id"] as? String } == ["zzzzzzzz"])
        let empty = try Played("server-fixtures")
        #expect(try empty.call("GET", "threads/s7ackr00/stack").0 == 200)
    }

    @Test func otherScenariosAreLeftAlone() throws {
        let played = try Played("paired")
        #expect(try played.call("GET", "threads/s7ackr00/stack").0 == -1)
    }

    private func serverFixturesWithOneChild() throws -> URL {
        let directory = FileManager.default.temporaryDirectory.appendingPathComponent(UUID().uuidString)
        let source = iosDirectory.appendingPathComponent("fixtures").resolvingSymlinksInPath()
        try FileManager.default.copyItem(at: source, to: directory)
        let stack = ["rootId": "s7ackr00", "isRoot": true, "children": [["id": "zzzzzzzz"]]] as [String: Any]
        try JSONSerialization.data(withJSONObject: stack).write(to: directory.appendingPathComponent("stack.json"))
        return directory
    }
}
