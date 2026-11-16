import Foundation
import Testing

@testable import YakStub

@Suite struct ChangesRoutesTests {
    private func context(_ scenario: String) throws -> StubContext {
        var directory = URL(fileURLWithPath: #filePath)
        while directory.lastPathComponent != "ios" { directory.deleteLastPathComponent() }
        let fixtures = try StubFixtures(
            directory: directory.appendingPathComponent("fixtures"),
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
        try await Task.sleep(for: .seconds(StubChanges.openingDuration + 0.3))
        let open = call(feature, "GET threads/d0e515ef", scenario).json
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
}
