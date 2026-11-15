import Foundation
import Testing

@testable import YakKit

/// The server's preview fixtures, read through `Fixtures` only. Each returns early until the fixture exists.
@Test(arguments: [
    "thread-preview-status", "thread-preview-status-starting", "thread-preview-status-failing",
    "thread-preview-ticket", "thread-preview-restore",
])
func decodesEveryPreviewFixture(name: String) throws {
    guard Fixtures.exists(name) else { return }
    let data = try Fixtures.data(name)
    switch name {
    case "thread-preview-status":
        let status = try JSONDecoder().decode(PreviewStatus.self, from: data)
        #expect(status.state == .ready && status.url != nil)
    case "thread-preview-status-starting":
        let status = try JSONDecoder().decode(PreviewStatus.self, from: data)
        #expect(status.state.isTransitional && status.url == nil)
    case "thread-preview-status-failing":
        let status = try JSONDecoder().decode(PreviewStatus.self, from: data)
        #expect(status.error != nil && status.setup.intent != nil)
    case "thread-preview-ticket":
        _ = try JSONDecoder().decode(PreviewTicket.self, from: data)
    default:
        #expect(try JSONDecoder().decode(StatusResponse.self, from: data).status == "restoring")
    }
}
