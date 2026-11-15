import Foundation
import Testing

@testable import YakKit

struct AttachmentFixtureDecodingTests {
    @Test func serverFixtureDecodes() throws {
        guard Fixtures.exists("thread-attachments") else {
            print("SKIPPED: the server fixture thread-attachments is not on this branch yet")
            return
        }
        let show = try JSONDecoder().decode(ThreadShow.self, from: Fixtures.data("thread-attachments"))
        let first = try #require(show.messages.first?.attachmentList.first)
        #expect(!first.url.isEmpty)
    }
}
