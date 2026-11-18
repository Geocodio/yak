import XCTest

extension StubControl {
    /// The widget tokens the stub holds, as the hex strings the app sent.
    func widgetTokens() throws -> [String] {
        let text = try get("widgets")
        let object = try XCTUnwrap(try JSONSerialization.jsonObject(with: Data(text.utf8)) as? [String: Any])
        return (object["tokens"] as? [[String: String]] ?? []).compactMap { $0["token"] }
    }
}
