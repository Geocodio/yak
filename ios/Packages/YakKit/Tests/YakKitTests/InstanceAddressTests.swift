import Foundation
import Testing

@testable import YakKit

@Suite struct InstanceAddressTests {
    @Test(arguments: [
        ("yak.example.com", "https://yak.example.com"),
        ("https://yak.example.com/", "https://yak.example.com"),
        ("HTTP://Yak.Example.com", "https://yak.example.com"),
        ("  yak.example.com  ", "https://yak.example.com"),
        ("https://yak.example.com/threads/abc", "https://yak.example.com"),
        ("yak.example.com:8443", "https://yak.example.com:8443"),
        ("yak.example.com:443", "https://yak.example.com"),
    ])
    func normalizesWhatPeopleType(input: String, expected: String) {
        #expect(InstanceAddress.normalize(input)?.absoluteString == expected)
    }

    @Test(arguments: ["", "   ", "not a host", "ftp://x", "https://", "yak.example.com:abc", "a@b.com"])
    func refusesNonsense(input: String) {
        #expect(InstanceAddress.normalize(input) == nil)
    }
}
