import Foundation
import Testing

@testable import YakKit

private struct WidgetReload: Decodable {
    struct Aps: Decodable {
        let contentChanged: Bool
        enum CodingKeys: String, CodingKey { case contentChanged = "content-changed" }
    }
    let aps: Aps
}

@Test(.enabled(if: Fixtures.exists("push/widget-reload")))
func decodesTheWidgetReloadPush() throws {
    let data = try Fixtures.data("push/widget-reload")
    #expect(try JSONDecoder().decode(WidgetReload.self, from: data).aps.contentChanged)
    let object = try #require(try JSONSerialization.jsonObject(with: data) as? [String: Any])
    #expect(Array(object.keys) == ["aps"])
}
