import Foundation
import Testing

@testable import YakStub

@Suite struct ChangesRoutesTests {
    private func iosDirectory() -> URL {
        var directory = URL(fileURLWithPath: #filePath)
        while directory.lastPathComponent != "ios" { directory.deleteLastPathComponent() }
        return directory
    }

    private func context(_ scenario: String, fixturesDirectory: URL? = nil) throws -> StubContext {
        let directory = iosDirectory()
        let fixtures = try StubFixtures(
            directory: fixturesDirectory ?? directory.appendingPathComponent("fixtures"),
            scenariosDirectory: directory.appendingPathComponent("Packages/YakKit/Tests/YakKitTests/Scenarios"))
        return StubContext(
            scenario: scenario, baseURL: "http://127.0.0.1", fixtures: fixtures, threads: StubThreads(),
            core: { .drop })
    }

    private func call(
        _ feature: StubChanges, _ route: String, _ scenario: StubContext, body: String = ""
    ) -> (status: Int, json: [String: Any]) {
        let method = String(route.split(separator: " ")[0])
        let reply = feature.answer(
            route: route, request: StubRequest(method: method, path: "x", body: Data(body.utf8)), context: scenario)
        guard case .reply(let status, _, let data)? = reply?.answer else { return (0, [:]) }
        return (status, (try? JSONSerialization.jsonObject(with: data)) as? [String: Any] ?? [:])
    }

    private func ids(_ answer: [String: Any]) -> [Int] {
        let files = (answer["diff"] as? [String: Any])?["files"] as? [[String: Any]] ?? []
        return files.compactMap { $0["id"] as? Int }
    }

    @Test func theShowCarriesTheChangeFields() throws {
        let show = call(StubChanges(), "GET threads/d0e515ef", try context("changes")).json
        #expect(show["canOpenPullRequest"] as? Bool == true)
        #expect((show["diffTotals"] as? [String: Any])?["filesCount"] as? Int == 4)
    }

    @Test func fileIdsFollowTheRecapture() throws {
        let feature = StubChanges()
        let scenario = try context("changes-replaced")
        #expect(call(feature, "GET threads/d0e515ef/diff/files/501", scenario).status == 404)
        #expect(ids(call(feature, "GET threads/d0e515ef/diff", scenario).json) == [601, 602, 603, 604])
        #expect(call(feature, "GET threads/d0e515ef/diff/files/601", scenario).status == 200)
        #expect(call(feature, "GET threads/d0e515ef/diff/files/501", scenario).status == 404)
        #expect(call(feature, "GET threads/d0e515ef/diff/files/602", scenario).json["isBinary"] as? Bool == true)
    }

    @Test func aGoneFileLeavesTheRecapturedList() throws {
        let feature = StubChanges()
        let scenario = try context("changes-gone")
        _ = call(feature, "GET threads/d0e515ef/diff/files/501", scenario)
        #expect(ids(call(feature, "GET threads/d0e515ef/diff", scenario).json).count == 4)
        feature.state.mutate { $0.recaptured = true }
        #expect(ids(call(feature, "GET threads/d0e515ef/diff", scenario).json).count == 3)
    }

    @Test func readingTheLeavingFileRecapturesWithoutIt() throws {
        let feature = StubChanges()
        let scenario = try context("changes-gone")
        #expect(call(feature, "GET threads/d0e515ef/diff/files/503", scenario).status == 404)
        #expect(ids(call(feature, "GET threads/d0e515ef/diff", scenario).json) == [601, 602, 604])
    }

    @Test func theSlowCardWaitsAndShowsAnOpenPullRequest() throws {
        let feature = StubChanges()
        let scenario = try context("pull-request-slow")
        let reply = feature.answer(
            route: "GET threads/d0e515ef/pull-requests",
            request: StubRequest(method: "GET", path: "x", body: Data()), context: scenario)
        #expect(reply?.delay == .seconds(14))
        let show = call(feature, "GET threads/d0e515ef", scenario).json
        #expect((show["pullRequest"] as? [String: Any])?["number"] as? Int == 41)
    }

    @Test func aPrivateOpenNeedsTheAcknowledgement() throws {
        let feature = StubChanges()
        let scenario = try context("changes-private")
        let refused = call(feature, "POST threads/d0e515ef/pull-request", scenario, body: "{}")
        #expect(refused.status == 422)
        let sent = call(
            feature, "POST threads/d0e515ef/pull-request", scenario, body: "{\"acknowledged_private_publish\":true}")
        #expect(sent.status == 202)
        #expect(feature.state.get().lastPullRequestBody.contains("true"))
    }

    @Test func openingBecomesOpenAfterTwoSeconds() async throws {
        let feature = StubChanges()
        let scenario = try context("changes")
        #expect(call(feature, "POST threads/d0e515ef/pull-request", scenario, body: "{}").status == 202)
        let opening = call(feature, "GET threads/d0e515ef", scenario).json["thread"] as? [String: Any]
        #expect((opening?["pullRequestOpening"] as? [String: Any])?["state"] as? String == "opening")
        // The pull request opens after the opening time; a loaded machine runs the timer late.
        var open = call(feature, "GET threads/d0e515ef", scenario).json
        for _ in 0..<300 where open["pullRequest"] is NSNull || open["pullRequest"] == nil {
            try await Task.sleep(for: .milliseconds(100))
            open = call(feature, "GET threads/d0e515ef", scenario).json
        }
        #expect((open["pullRequest"] as? [String: Any])?["number"] as? Int == 41)
    }

    @Test func aRefusedOpenAnswersItsMessage() throws {
        let refused = call(
            StubChanges(), "POST threads/d0e515ef/pull-request", try context("pull-request-refused"), body: "{}")
        #expect(refused.status == 409)
    }

    @Test func fixAnswersOnlyForTheFailingRow() throws {
        let feature = StubChanges()
        let scenario = try context("changes")
        #expect(call(feature, "POST threads/d0e515ef/pull-requests/78/fix", scenario).status == 200)
        #expect(call(feature, "POST threads/d0e515ef/pull-requests/77/fix", scenario).status == 409)
    }

    @Test func everyNewPairHasScenarioData() throws {
        let scenario = try context("changes")
        for pair in StubChanges().fixturePairs {
            #expect(scenario.fixtures.scenarioFiles[pair.scenario] != nil, "\(pair.scenario)")
        }
    }

    @Test func openingPublishesAThreadEvent() async throws {
        let feature = StubChanges()
        let sent = Locked<[String]>([])
        feature.attach { thread, json in sent.mutate { $0.append("\(thread) \(json)") } }
        let scenario = try context("changes")
        #expect(call(feature, "POST threads/d0e515ef/pull-request", scenario, body: "{}").status == 202)
        #expect(sent.get().isEmpty)
        try await Task.sleep(for: .seconds(StubChanges.openingDuration + 0.3))
        // A loaded machine can run the timer late: wait for the event instead of trusting one sleep.
        for _ in 0..<300 where sent.get().isEmpty { try await Task.sleep(for: .milliseconds(100)) }
        let events = sent.get()
        try #require(events.count == 1)
        guard events.count == 1 else { return }
        let frame = events[0].split(separator: " ", maxSplits: 1).map(String.init)
        let event = (try? JSONSerialization.jsonObject(with: Data(frame[1].utf8))) as? [String: Any] ?? [:]
        #expect(frame[0] == "d0e515ef")
        #expect(event["type"] as? String == "thread")
        #expect(event["sequence"] as? Int == 15)
        let show = call(feature, "GET threads/d0e515ef", scenario).json
        #expect((show["pullRequest"] as? [String: Any])?["number"] as? Int == 41)
        #expect(show["latestSequence"] as? Int == 15)
    }

    /// The thread show keeps the scenario's own latest sequence when it is further along than the changes events, so
    /// a long history opens at its newest page.
    @Test func theShowKeepsALaterLatestSequenceOfTheScenario() throws {
        let base = try context("long-history")
        let core = Scenario.longHistory.reply(
            to: StubRequest(method: "GET", path: "/api/app/v1/threads/d0e515ef", body: Data()),
            baseURL: "http://127.0.0.1", fixtures: base.fixtures, threads: base.threads
        ).answer
        let scenario = StubContext(
            scenario: "long-history", baseURL: base.baseURL, fixtures: base.fixtures, threads: base.threads,
            core: { core })
        #expect(call(StubChanges(), "GET threads/d0e515ef", scenario).json["latestSequence"] as? Int == 1200)
    }

    @Test func aWorkingThreadPlaysItsEventsAndEndsTheTurn() async throws {
        let feature = StubChanges()
        let types = Locked<[String]>([])
        feature.attach { _, json in
            let event = (try? JSONSerialization.jsonObject(with: Data(json.utf8))) as? [String: Any]
            types.mutate { $0.append(event?["type"] as? String ?? "") }
        }
        let scenario = try context("changes-working")
        let before = call(feature, "GET threads/d0e515ef/diff", scenario).json
        #expect((before["diff"] as? [String: Any])?["isTurnRunning"] as? Bool == true)
        feature.subscribed(thread: "d0e515ef", scenario: "changes-working", fixtures: scenario.fixtures)
        feature.subscribed(thread: "d0e515ef", scenario: "changes-working", fixtures: scenario.fixtures)
        // Eight events at 0.4 seconds each; a loaded machine plays them late. The short wait after the
        // eighth shows that subscribing twice does not play them twice.
        for _ in 0..<600 where types.get().count < 8 { try await Task.sleep(for: .milliseconds(100)) }
        try await Task.sleep(for: .milliseconds(800))
        #expect(types.get().count == 8)
        #expect(types.get().last == "diff.updated")
        let after = call(feature, "GET threads/d0e515ef/diff", scenario).json
        #expect((after["diff"] as? [String: Any])?["isTurnRunning"] as? Bool == false)
        #expect(ids(after) == [601, 602, 603, 604])
    }

    @Test func nullFieldsReachTheApp() throws {
        let feature = StubChanges()
        let scenario = try context("tasks-nulls")
        let tasks = call(feature, "GET tasks", scenario).json["tasks"] as? [[String: Any]] ?? []
        #expect((tasks[0]["pr"] as? [String: Any])?["url"] is NSNull)
        #expect(tasks[1]["createdAt"] is NSNull)
        let rows = call(feature, "GET observations", scenario).json["observations"] as? [[String: Any]] ?? []
        #expect(rows[0]["repo"] is NSNull)
    }

    @Test func namedScenariosNeverReadServerFixtures() throws {
        let copy = FileManager.default.temporaryDirectory.appendingPathComponent(UUID().uuidString)
        try FileManager.default.createDirectory(at: copy, withIntermediateDirectories: true)
        let original = iosDirectory().appendingPathComponent("fixtures").resolvingSymlinksInPath()
        for name in try FileManager.default.contentsOfDirectory(atPath: original.path) where name.hasSuffix(".json") {
            try FileManager.default.copyItem(
                at: original.appendingPathComponent(name), to: copy.appendingPathComponent(name))
        }
        defer { try? FileManager.default.removeItem(at: copy) }
        let one = #"{"diff":{"files":[{"id":9,"path":"zzz","oldPath":null,"status":"added"}]}}"#
        try Data(one.utf8).write(to: copy.appendingPathComponent("thread-diff.json"))
        let answer = call(StubChanges(), "GET threads/d0e515ef/diff", try context("changes", fixturesDirectory: copy))
        #expect(ids(answer.json) == [501, 502, 503, 504])
    }
}
