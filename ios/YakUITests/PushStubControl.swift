import XCTest

extension StubControl {
    /// What the stub holds for push: `tokens` per kind, the reported `activities` and the saved `preferences`.
    func pushState() throws -> [String: Any] {
        let text = try get("push")
        return try XCTUnwrap(try JSONSerialization.jsonObject(with: Data(text.utf8)) as? [String: Any])
    }

    /// A push scenario file with its `yak.instance` set to the stub's address, base64 encoded for
    /// `-yakTestPushTap` and `yak://test-push`.
    func pushPayload(_ scenarioName: String) throws -> String {
        var directory = URL(fileURLWithPath: #filePath)
        while directory.lastPathComponent != "ios" { directory.deleteLastPathComponent() }
        let file = directory.appendingPathComponent("Packages/YakKit/Tests/YakKitTests/Scenarios/\(scenarioName).json")
        var object = try XCTUnwrap(try JSONSerialization.jsonObject(with: Data(contentsOf: file)) as? [String: Any])
        var yak = object["yak"] as? [String: Any] ?? [:]
        yak["instance"] = url
        object["yak"] = yak
        return try JSONSerialization.data(withJSONObject: object).base64EncodedString()
    }
}
