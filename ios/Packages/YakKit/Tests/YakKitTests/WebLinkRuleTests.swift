import Foundation
import Testing

@testable import YakKit

@Suite struct WebLinkRuleTests {
    @Test func httpsIsAccepted() {
        #expect(WebLinkRule.safeURL("https://example.test/pull/1")?.absoluteString == "https://example.test/pull/1")
    }

    @Test(arguments: [
        "http://example.test/", "javascript:alert(1)", "file:///etc/passwd", "https:///path",
        "https://user:pass@example.test/",
        "https://user@example.test/", "", "not a url", "tel:123",
    ])
    func everythingElseIsRejected(_ text: String) {
        #expect(WebLinkRule.safeURL(text) == nil)
    }

    @Test func nilIsRejected() {
        #expect(WebLinkRule.safeURL(nil) == nil)
    }
}
