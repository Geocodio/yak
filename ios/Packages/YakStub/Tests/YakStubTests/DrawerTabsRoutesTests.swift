import Foundation
import Testing

@testable import YakStub

private var iosDirectory: URL {
    var directory = URL(fileURLWithPath: #filePath)
    while directory.lastPathComponent != "ios" { directory.deleteLastPathComponent() }
    return directory
}

private func object(_ scenario: Scenario, _ target: String) throws -> [String: Any] {
    let fixtures = try StubFixtures(
        directory: iosDirectory.appendingPathComponent("fixtures"),
        scenariosDirectory: iosDirectory.appendingPathComponent("Packages/YakKit/Tests/YakKitTests/Scenarios"))
    let parsed = StubServer.parseTarget("/api/app/v1/\(target)")
    let request = StubRequest(
        method: "GET", path: parsed.path, query: parsed.query, headers: ["authorization": "Bearer t"])
    let reply = scenario.reply(to: request, baseURL: "http://127.0.0.1:1", fixtures: fixtures)
    guard case .reply(_, _, let body) = reply.answer else { return [:] }
    return try #require(try JSONSerialization.jsonObject(with: body) as? [String: Any])
}

private func titles(_ list: [String: Any], _ key: String = "threads") -> [String] {
    (list[key] as? [[String: Any]] ?? []).compactMap { $0["title"] as? String }
}

@Suite struct DrawerTabsRoutesTests {
    @Test func everyTabAnswersItsOwnRows() throws {
        #expect(try titles(object(.drawerTabs, "threads?filter=mine")).count == 3)
        #expect(try titles(object(.drawerTabs, "threads?filter=mine"), "needsYou").count == 2)
        #expect(try titles(object(.drawerTabs, "threads?filter=everyone")).count == 5)
        #expect(try titles(object(.drawerTabs, "threads?filter=closed")).count == 3)
        let review = try object(.drawerTabs, "threads?filter=review")
        let groups = try #require(review["reviewGroups"] as? [[String: Any]])
        #expect(groups.map { $0["key"] as? String } == ["back_to_you", "not_reviewed", "with_yak", "approved"])
        #expect(review["reviewCount"] as? Int == 2 && titles(review).isEmpty)
    }

    @Test func sourcesStartersAndSearchNarrowTheTab() throws {
        let sources = try object(.drawerTabs, "threads?filter=everyone&sources[]=slack&sources[]=linear")
        #expect(titles(sources).count == 2)
        let yak = try object(.drawerTabs, "threads?filter=mine&starters[]=yak")
        #expect(titles(yak) == ["Move search to the new index"])
        let found = try object(.drawerTabs, "threads?filter=closed&search=pricing")
        #expect(titles(found) == ["Typo on the pricing page"])
    }

    @Test func theQuietScenarioHasNoReviewCountAndTheOlderServerHasNoNewKeys() throws {
        #expect(try object(.drawerTabsQuiet, "threads?filter=mine")["reviewCount"] as? Int == 0)
        let older = try object(.drawerTabsOlder, "threads?filter=mine")
        #expect(older["reviewCount"] == nil && older["facets"] == nil)
    }

    @Test func meListsTheFiltersExceptOnAnOlderServer() throws {
        #expect((try object(.drawerTabs, "me")["threadFilters"] as? [String])?.contains("review") == true)
        #expect(try object(.drawerTabsOlder, "me")["threadFilters"] == nil)
    }
}
