import Foundation
import Testing

@testable import YakKit

@Suite struct WalkthroughVideoLinkTests {
    private func instance(url: String = "https://yak.example.com", canonical: String? = nil) -> Instance {
        Instance(
            url: URL(string: url)!, name: "Example Yak", userName: "Test person",
            lastUsedAt: Date(timeIntervalSince1970: 0), canonicalURL: canonical.flatMap { URL(string: $0) })
    }

    private let signed = "https://yak.example.com/artifacts/k3m9p2qa/walkthrough.mp4?expires=1&signature=ab"

    @Test func theInstancesOwnSignedLinkPlays() {
        #expect(
            WalkthroughVideoLink.playable(signed, instance: instance(), allowsLoopbackHTTP: false)
                == URL(string: signed))
    }

    @Test func theCanonicalAddressPlays() {
        let alias = "https://alias.example.com/artifacts/k3m9p2qa/walkthrough.mp4?signature=ab"
        let own = instance(canonical: "https://alias.example.com")
        #expect(WalkthroughVideoLink.playable(alias, instance: own, allowsLoopbackHTTP: false) != nil)
    }

    @Test(arguments: [
        "https://other.example.com/a.mp4",
        "https://yak.example.com:8443/a.mp4",
        "http://yak.example.com/a.mp4",
        "https://user:pass@yak.example.com/a.mp4",
        "file:///tmp/a.mp4",
        "javascript:alert(1)",
        "data:video/mp4;base64,AA",
        "/artifacts/a.mp4",
        "https://yak.example.com.evil.example/a.mp4",
        "https://yаk.example.com/a.mp4",
        "",
    ])
    func refusesForeignLinks(raw: String) {
        #expect(WalkthroughVideoLink.playable(raw, instance: instance(), allowsLoopbackHTTP: false) == nil)
    }

    @Test func refusesNilAndOverLongLinks() {
        #expect(WalkthroughVideoLink.playable(nil, instance: instance(), allowsLoopbackHTTP: false) == nil)
        let padded = signed + String(repeating: "a", count: WalkthroughVideoLink.maximumLength + 1 - signed.count)
        #expect(padded.count == 2_049)
        #expect(WalkthroughVideoLink.playable(padded, instance: instance(), allowsLoopbackHTTP: false) == nil)
    }

    @Test func loopbackOnlyInTestMode() {
        let stub = instance(url: "http://127.0.0.1:8123")
        let link = "http://127.0.0.1:8123/__stub/walkthrough-video/sample.mp4"
        #expect(WalkthroughVideoLink.playable(link, instance: stub, allowsLoopbackHTTP: false) == nil)
        #expect(WalkthroughVideoLink.playable(link, instance: stub, allowsLoopbackHTTP: true) == URL(string: link))
        let other = "http://127.0.0.2:8123/__stub/walkthrough-video/sample.mp4"
        let otherStub = instance(url: "http://127.0.0.2:8123")
        #expect(WalkthroughVideoLink.playable(other, instance: otherStub, allowsLoopbackHTTP: false) == nil)
        #expect(WalkthroughVideoLink.playable(other, instance: otherStub, allowsLoopbackHTTP: true) == nil)
    }
}
