import Foundation
import Testing

@testable import YakKit

@Suite struct PairingLinkTests {
    @Test func parsesAPairingLink() {
        let link = PairingLink(
            url: URL(string: "yak://pair?url=https%3A%2F%2Fyak.example.com&code=abc_DEF-123")!)
        #expect(link?.instance.absoluteString == "https://yak.example.com")
        #expect(link?.code == "abc_DEF-123")
    }

    @Test func refusesLinksThatAreNotPairing() {
        #expect(PairingLink(url: URL(string: "yak://pair?url=http%3A%2F%2Fyak.example.com&code=x")!) == nil)
        #expect(PairingLink(url: URL(string: "yak://thread?id=1")!) == nil)
        #expect(PairingLink(url: URL(string: "yak://pair?url=https%3A%2F%2Fyak.example.com")!) == nil)
        #expect(PairingLink(url: URL(string: "https://pair?url=https%3A%2F%2Fyak.example.com&code=x")!) == nil)
    }
}
