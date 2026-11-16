import Foundation
import Testing

@testable import YakKit

struct ParseThrottleTests {
    @Test func throttleParsesAtMostTenTimesASecond() {
        var throttle = ParseThrottle()
        let start = Date(timeIntervalSince1970: 0)
        #expect(throttle.request(now: start) == .now)
        throttle.parsed(at: start)
        #expect(throttle.request(now: start.addingTimeInterval(0.03)) == .later(.milliseconds(70)))
        #expect(throttle.request(now: start.addingTimeInterval(0.05)) == .alreadyScheduled)
        throttle.parsed(at: start.addingTimeInterval(0.1))
        #expect(throttle.request(now: start.addingTimeInterval(0.25)) == .now)
    }
}
