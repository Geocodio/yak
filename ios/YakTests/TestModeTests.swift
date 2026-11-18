import Foundation
import Testing
import YakKit

@testable import Yak

@Suite struct TestModeTests {
    @Test func loopbackStaysPlainHTTPAndEverythingElseIsUpgraded() {
        #expect(TestMode.normalize("127.0.0.1:8080")?.absoluteString == "http://127.0.0.1:8080")
        #expect(TestMode.normalize(" http://127.0.0.1:8080/path ")?.absoluteString == "http://127.0.0.1:8080")
        #expect(TestMode.normalize("yak.example.com")?.absoluteString == "https://yak.example.com")
        #expect(TestMode.normalize("127.0.0.1.example.com")?.absoluteString == "https://127.0.0.1.example.com")
    }

    @Test func onlyLoopbackLinksAreRelaxed() {
        let loopback = URL(string: "yak://pair?url=http%3A%2F%2F127.0.0.1%3A8080&code=abc")!
        #expect(TestMode.parse(loopback)?.instance.absoluteString == "http://127.0.0.1:8080")
        #expect(TestMode.parse(URL(string: "yak://pair?url=http%3A%2F%2Fyak.example.com&code=abc")!) == nil)
        #expect(TestMode.parse(URL(string: "yak://pair?url=https%3A%2F%2Fyak.example.com&code=abc")!) != nil)
    }

    @MainActor @Test func theNetworkMonitorFollowsItsPollInsteadOfTheRealPath() async throws {
        let answer = LockedFlag(false)
        let monitor = NetworkMonitor(poll: { answer.value }, interval: .milliseconds(10))
        try await waitUntil { !monitor.isOnline }
        answer.value = true
        try await waitUntil { monitor.isOnline }
    }

    @MainActor private func waitUntil(_ condition: () -> Bool) async throws {
        #expect(await TestTimeScale.poll(condition))
    }
}

private final class LockedFlag: @unchecked Sendable {
    private let lock = NSLock()
    private var flag: Bool
    init(_ flag: Bool) { self.flag = flag }
    var value: Bool {
        get { lock.withLock { flag } }
        set { lock.withLock { flag = newValue } }
    }
}
