import Foundation
import Testing
import YakKit

@testable import Yak

private var iosDirectory: URL {
    var directory = URL(fileURLWithPath: #filePath)
    while directory.lastPathComponent != "ios" { directory.deleteLastPathComponent() }
    return directory
}

private func jsonObject(at url: URL) throws -> [String: Any] {
    let data = try Data(contentsOf: url)
    return try #require(try JSONSerialization.jsonObject(with: data) as? [String: Any])
}

@Suite struct ThreadActivityAttributesTests {
    @Test func decodesTheScenarioStartPush() throws {
        let scenarios = iosDirectory.appendingPathComponent("Packages/YakKit/Tests/YakKitTests/Scenarios")
        let push = try jsonObject(at: scenarios.appendingPathComponent("scenario-push-activity-start.json"))
        let aps = try #require(push["aps"] as? [String: Any])
        let attributes = try JSONDecoder().decode(
            ThreadActivityAttributes.self,
            from: JSONSerialization.data(withJSONObject: try #require(aps["attributes"])))
        #expect(attributes.threadId == "d0e515ef")
        let state = try JSONDecoder().decode(
            ThreadActivityAttributes.ContentState.self,
            from: JSONSerialization.data(withJSONObject: try #require(aps["content-state"])))
        #expect(state.state == .working)
    }

    @Test func theTypeNameIsTheContractName() {
        #expect(String(describing: ThreadActivityAttributes.self) == "ThreadActivityAttributes")
    }

    @Test(arguments: ["live-activity-start", "live-activity-start-private"])
    func decodesTheServerStartPushes(name: String) throws {
        let file = iosDirectory.appendingPathComponent("fixtures/push/\(name).json")
        try #require(FileManager.default.fileExists(atPath: file.path), "the server fixture \(name) is missing")
        let aps = try #require(try jsonObject(at: file)["aps"] as? [String: Any])
        _ = try JSONDecoder().decode(
            ThreadActivityAttributes.self,
            from: JSONSerialization.data(withJSONObject: try #require(aps["attributes"])))
        _ = try JSONDecoder().decode(
            ThreadActivityAttributes.ContentState.self,
            from: JSONSerialization.data(withJSONObject: try #require(aps["content-state"])))
    }
}
