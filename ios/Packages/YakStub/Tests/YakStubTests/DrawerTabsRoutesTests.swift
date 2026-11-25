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

    @Test func theLinkScenarioAsksToLinkGitHubOnReviewOnly() throws {
        let review = try object(.drawerTabsLink, "threads?filter=review")
        #expect(
            review["needsGitHubLink"] as? Bool == true && review["gitHubLinkPath"] as? String == "/settings/profile")
        let groups = try #require(review["reviewGroups"] as? [[String: Any]])
        #expect(groups.allSatisfy { ($0["rows"] as? [[String: Any]])?.isEmpty == true })
        #expect(try object(.drawerTabsLink, "threads?filter=mine")["needsGitHubLink"] as? Bool == false)
    }

    @Test func meListsTheFiltersExceptOnAnOlderServer() throws {
        #expect((try object(.drawerTabs, "me")["threadFilters"] as? [String])?.contains("review") == true)
        #expect(try object(.drawerTabsOlder, "me")["threadFilters"] == nil)
    }

    @Test func theReviewLineScenarioHasEveryStateAndAFailedRowWithAnError() throws {
        let groups = try #require(try object(.reviewLine, "threads?filter=review")["reviewGroups"] as? [[String: Any]])
        #expect(groups.compactMap { $0["key"] as? String } == ["back_to_you", "not_reviewed", "with_yak", "approved"])
        let rows = groups.flatMap { $0["rows"] as? [[String: Any]] ?? [] }
        let counts = rows.compactMap { (($0["review"] as? [String: Any])?["pullRequests"] as? [Any])?.count }
        #expect(counts.contains(2))
        let failed = try #require(
            (try object(.reviewLine, "threads?filter=mine")["needsYou"] as? [[String: Any]])?.first {
                $0["needsYouLabel"] as? String == "Failed"
            })
        #expect((failed["needsYouDetail"] as? String)?.count ?? 0 > 100)
    }

    @Test func theEmptyReviewScenarioHasFourEmptyGroupsAndNoLinkRequest() throws {
        let review = try object(.reviewLineEmpty, "threads?filter=review")
        let groups = try #require(review["reviewGroups"] as? [[String: Any]])
        #expect(groups.count == 4 && groups.allSatisfy { ($0["rows"] as? [[String: Any]])?.isEmpty == true })
        #expect(review["needsGitHubLink"] as? Bool == false && review["reviewCount"] as? Int == 0)
    }
}
