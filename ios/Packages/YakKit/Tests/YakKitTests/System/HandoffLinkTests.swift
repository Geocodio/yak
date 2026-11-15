import Foundation
import Testing

@testable import YakKit

@Suite struct HandoffLinkTests {
    @Test func buildsTheThreadPage() {
        let url = HandoffLink.webURL(instance: URL(string: "https://yak.example.com")!, threadID: "d0e515ef")
        #expect(url?.absoluteString == "https://yak.example.com/threads/d0e515ef")
    }

    @Test func keepsAPathPrefix() {
        let url = HandoffLink.webURL(instance: URL(string: "https://example.com/yak/")!, threadID: "d0e515ef")
        #expect(url?.absoluteString == "https://example.com/yak/threads/d0e515ef")
    }

    @Test func refusesHostileInput() {
        let instances = [
            "http://yak.example.com", "https://user:pass@yak.example.com", "https://yak.example.com?x=1",
            "https://yak.example.com#f", "yak://pair", "file:///etc",
        ]
        for text in instances {
            #expect(HandoffLink.webURL(instance: URL(string: text)!, threadID: "d0e515ef") == nil, "\(text)")
        }
        let good = URL(string: "https://yak.example.com")!
        for id in ["../x", "%2F%2Fabcd", "d0e515e", "d0e515efg", "", "d0e5/5ef"] {
            #expect(HandoffLink.webURL(instance: good, threadID: id) == nil, "\(id)")
        }
    }

    @Test func testModeAllowsTheStub() {
        let url = HandoffLink.webURL(
            instance: URL(string: "http://127.0.0.1:8099")!, threadID: "d0e515ef", allowsInsecure: true)
        #expect(url?.absoluteString == "http://127.0.0.1:8099/threads/d0e515ef")
    }
}
