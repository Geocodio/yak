import Foundation
import Testing

@testable import YakKit

@Suite struct EmbedNavigationPolicyTests {
    private let policy = EmbedNavigationPolicy(instanceURL: URL(string: "https://yak.example.com")!)
    private let loopback = EmbedNavigationPolicy(
        instanceURL: URL(string: "http://127.0.0.1:8123")!, allowsLoopbackHTTP: true)

    private struct Case: Sendable, CustomTestStringConvertible {
        let url: String?
        let method: String?
        let isMainFrame: Bool
        let isUserInitiated: Bool
        let expected: EmbedNavigationPolicy.Decision
        var testDescription: String { "\(method ?? "-") \(url ?? "nil") main:\(isMainFrame) tap:\(isUserInitiated)" }
    }

    private static let cases: [Case] = [
        Case(
            url: "https://yak.example.com/app/embed/session", method: "POST", isMainFrame: true, isUserInitiated: false,
            expected: .allow),
        Case(
            url: "https://yak.example.com/app/embed/session", method: "GET", isMainFrame: true, isUserInitiated: false,
            expected: .cancel),
        Case(
            url: "https://yak.example.com/app/embed/session?to=/x", method: "POST", isMainFrame: true,
            isUserInitiated: false, expected: .cancel),
        Case(
            url: "https://yak.example.com/app/embed/threads/d0e515ef", method: "GET", isMainFrame: true,
            isUserInitiated: false, expected: .allow),
        Case(
            url: "https://yak.example.com/app/embed/threads/d0e515ef", method: nil, isMainFrame: true,
            isUserInitiated: false, expected: .allow),
        Case(
            url: "https://yak.example.com/app/embed/threads/d0e515ef", method: "POST", isMainFrame: true,
            isUserInitiated: false, expected: .cancel),
        Case(
            url: "https://yak.example.com/app/embed/../threads/d0e515ef", method: "GET", isMainFrame: true,
            isUserInitiated: true,
            expected: .openLink(URL(string: "https://yak.example.com/app/embed/../threads/d0e515ef")!)),
        // The instance's own full pages and other hosts go to the browser, only after a tap.
        Case(
            url: "https://yak.example.com/threads/d0e515ef", method: "GET", isMainFrame: true, isUserInitiated: true,
            expected: .openLink(URL(string: "https://yak.example.com/threads/d0e515ef")!)),
        Case(
            url: "https://yak.example.com/threads/d0e515ef", method: "GET", isMainFrame: true, isUserInitiated: false,
            expected: .cancel),
        Case(
            url: "https://example.com/docs", method: "GET", isMainFrame: true, isUserInitiated: true,
            expected: .openLink(URL(string: "https://example.com/docs")!)),
        Case(
            url: "https://example.com/docs", method: "GET", isMainFrame: true, isUserInitiated: false,
            expected: .cancel),
        // Another host cannot borrow the embed path.
        Case(
            url: "https://evil.example.net/app/embed/threads/d0e515ef", method: "GET", isMainFrame: true,
            isUserInitiated: true,
            expected: .openLink(URL(string: "https://evil.example.net/app/embed/threads/d0e515ef")!)),
        Case(
            url: "https://yak.example.com:8443/app/embed/threads/d0e515ef", method: "GET", isMainFrame: true,
            isUserInitiated: false, expected: .cancel),
        Case(
            url: "http://yak.example.com/app/embed/threads/d0e515ef", method: "GET", isMainFrame: true,
            isUserInitiated: false, expected: .cancel),
        Case(url: "javascript:alert(1)", method: "GET", isMainFrame: true, isUserInitiated: true, expected: .cancel),
        Case(
            url: "data:text/html,hi", method: "GET", isMainFrame: true, isUserInitiated: true, expected: .cancel),
        Case(url: "tel:+4512345678", method: "GET", isMainFrame: true, isUserInitiated: true, expected: .cancel),
        Case(url: nil, method: "GET", isMainFrame: true, isUserInitiated: true, expected: .cancel),
        // Frames other than the main one.
        Case(url: "about:blank", method: "GET", isMainFrame: false, isUserInitiated: false, expected: .allow),
        Case(
            url: "https://yak.example.com/app/embed/threads/d0e515ef", method: "GET", isMainFrame: false,
            isUserInitiated: true, expected: .cancel),
        Case(
            url: "https://example.com/video", method: "GET", isMainFrame: false, isUserInitiated: true,
            expected: .cancel),
    ]

    @Test(arguments: cases)
    private func decidesNavigations(_ item: Case) {
        let decision = policy.decide(
            url: item.url.flatMap(URL.init(string:)), method: item.method, isMainFrame: item.isMainFrame,
            isUserInitiated: item.isUserInitiated)
        #expect(decision == item.expected)
    }

    @Test func loopbackHTTPWorksOnlyWhenAllowed() {
        let page = URL(string: "http://127.0.0.1:8123/app/embed/threads/d0e515ef")!
        #expect(loopback.decide(url: page, method: "GET", isMainFrame: true, isUserInitiated: false) == .allow)
        let plain = EmbedNavigationPolicy(instanceURL: URL(string: "http://127.0.0.1:8123")!)
        #expect(plain.decide(url: page, method: "GET", isMainFrame: true, isUserInitiated: false) == .cancel)
        let otherPort = URL(string: "http://127.0.0.1:9/app/embed/threads/d0e515ef")!
        #expect(loopback.decide(url: otherPort, method: "GET", isMainFrame: true, isUserInitiated: false) == .cancel)
    }

    @Test func checksTheOriginOfAMessage() {
        #expect(policy.isInstanceOrigin(scheme: "https", host: "yak.example.com", port: 0))
        #expect(policy.isInstanceOrigin(scheme: "HTTPS", host: "YAK.example.com", port: 443) == false)
        #expect(policy.isInstanceOrigin(scheme: "https", host: "yak.example.com", port: 8443) == false)
        #expect(policy.isInstanceOrigin(scheme: "http", host: "yak.example.com", port: 0) == false)
        #expect(policy.isInstanceOrigin(scheme: "https", host: "yak.example.com.evil.net", port: 0) == false)
        #expect(policy.isInstanceOrigin(scheme: "https", host: "", port: 0) == false)
        #expect(loopback.isInstanceOrigin(scheme: "http", host: "127.0.0.1", port: 8123))
        #expect(loopback.isInstanceOrigin(scheme: "http", host: "127.0.0.1", port: 8124) == false)
    }

    @Test func answersWithAnErrorStatusFeedTheMachineAndAreNotShown() {
        let page = URL(string: "https://yak.example.com/app/embed/threads/d0e515ef")!
        #expect(policy.outcome(ofResponseFrom: page, status: 401, isMainFrame: true) == .http(401))
        #expect(policy.outcome(ofResponseFrom: page, status: 404, isMainFrame: true) == .http(404))
        #expect(policy.outcome(ofResponseFrom: page, status: 503, isMainFrame: true) == .http(503))
        #expect(policy.outcome(ofResponseFrom: page, status: 200, isMainFrame: true) == nil)
        #expect(policy.outcome(ofResponseFrom: page, status: 401, isMainFrame: false) == nil)
        let elsewhere = URL(string: "https://example.com/")!
        #expect(policy.outcome(ofResponseFrom: elsewhere, status: 500, isMainFrame: true) == nil)
    }
}

@Suite struct QuickLookFileTests {
    @Test func namesStayInOneFolder() {
        #expect(QuickLookFile.fileName("report.pdf") == "report.pdf")
        #expect(QuickLookFile.fileName("../../etc/passwd") == "_.._etc_passwd")
        #expect(QuickLookFile.fileName("a\\b:c") == "a_b_c")
        #expect(QuickLookFile.fileName("..") == QuickLookFile.fallbackName)
        #expect(QuickLookFile.fileName(".hidden") == "hidden")
        #expect(QuickLookFile.fileName("   ") == QuickLookFile.fallbackName)
        #expect(QuickLookFile.fileName("line\nbreak.txt") == "line_break.txt")
    }

    @Test func longNamesKeepTheirExtension() {
        let name = QuickLookFile.fileName(String(repeating: "a", count: 300) + ".pdf")
        #expect(name.utf8.count <= 100)
        #expect(name.hasSuffix(".pdf"))
        let emoji = QuickLookFile.fileName(String(repeating: "\u{1F600}", count: 80) + ".png")
        #expect(emoji.utf8.count <= 100 && emoji.hasSuffix(".png"))
    }

    @Test func decidesFromTheAnnouncedLengthAndTheProgress() {
        #expect(QuickLookFile.admits(expectedLength: 0))
        #expect(QuickLookFile.admits(expectedLength: QuickLookFile.maximumBytes))
        #expect(!QuickLookFile.admits(expectedLength: -1))
        #expect(!QuickLookFile.admits(expectedLength: QuickLookFile.maximumBytes + 1))
        #expect(!QuickLookFile.mustCancel(receivedBytes: QuickLookFile.maximumBytes))
        #expect(QuickLookFile.mustCancel(receivedBytes: QuickLookFile.maximumBytes + 1))
    }

    @Test func capsTheSize() {
        #expect(QuickLookFile.isWithinLimit(bytes: 0))
        #expect(QuickLookFile.isWithinLimit(bytes: QuickLookFile.maximumBytes))
        #expect(QuickLookFile.isWithinLimit(bytes: QuickLookFile.maximumBytes + 1) == false)
    }
}
