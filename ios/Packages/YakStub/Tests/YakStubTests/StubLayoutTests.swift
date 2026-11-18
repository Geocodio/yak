import Foundation
import Testing

@testable import YakStub

@Suite struct StubLayoutTests {
    private func call(_ route: String, scenario: String) throws -> [String: Any]? {
        let directory = URL(fileURLWithPath: #filePath).deletingLastPathComponent()
        var root = directory
        while root.lastPathComponent != "ios" { root.deleteLastPathComponent() }
        let fixtures = try StubFixtures(
            directory: root.appendingPathComponent("fixtures"),
            scenariosDirectory: root.appendingPathComponent("Packages/YakKit/Tests/YakKitTests/Scenarios"))
        let show = Data(#"{"panes":{"chat":true,"preview":false,"terminal":false,"changes":false,"files":false}}"#.utf8)
        let context = StubContext(
            scenario: scenario, baseURL: "http://127.0.0.1", fixtures: fixtures, threads: StubThreads(),
            core: { .reply(status: 200, contentType: "application/json", body: show) })
        let request = StubRequest(method: "GET", path: Scenario.apiPrefix + route, body: Data())
        guard let reply = StubLayout().answer(route: "GET \(route)", request: request, context: context),
            case .reply(_, _, let body) = reply.answer
        else { return nil }
        return (try? JSONSerialization.jsonObject(with: body)) as? [String: Any]
    }

    @Test func twoPanesTurnsEveryPaneOn() throws {
        let panes = try #require(try call("threads/d0e515ef", scenario: "two-panes")?["panes"] as? [String: Bool])
        #expect(panes.count == 5 && panes.values.allSatisfy { $0 })
    }

    @Test func otherThreadsAndScenariosAreUnchanged() throws {
        #expect(try call("threads/a1b2c3d4", scenario: "two-panes") == nil)
        #expect(try call("threads/d0e515ef", scenario: "paired") == nil)
    }
}
