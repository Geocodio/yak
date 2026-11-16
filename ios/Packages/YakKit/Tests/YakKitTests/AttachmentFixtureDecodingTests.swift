import Foundation
import Testing

@testable import YakKit

struct AttachmentFixtureDecodingTests {
    @Test func serverFixtureDecodes() throws {
        #expect(Fixtures.exists("thread-attachments"), "the server fixture thread-attachments must exist")
        let show = try JSONDecoder().decode(ThreadShow.self, from: Fixtures.data("thread-attachments"))
        let first = try #require(show.messages.first?.attachmentList.first)
        #expect(!first.url.isEmpty)
        #expect(!first.name.isEmpty && first.size > 0)
    }
}
