import Foundation
import Testing

@testable import Yak

@Suite struct ActivityStartLedgerTests {
    @Test func aParsableTurnStartIsUsedAsGiven() {
        let ledger = ActivityStartLedger()
        let date = ledger.startedAt(
            of: "a", turnStartedAt: "2026-05-01T10:00:00.250Z", now: Date(timeIntervalSince1970: 5))
        #expect(date == Date(timeIntervalSince1970: 1_777_629_600.25))
    }

    @Test func anUnparsableTurnStartIsRememberedFromTheFirstSighting() {
        let ledger = ActivityStartLedger()
        let first = Date(timeIntervalSince1970: 100)
        #expect(ledger.startedAt(of: "a", turnStartedAt: "", now: first) == first)
        #expect(ledger.startedAt(of: "a", turnStartedAt: "", now: first.addingTimeInterval(60)) == first)
    }

    @Test func aLaterActivityCountsAsNewer() {
        let ledger = ActivityStartLedger()
        let first = Date(timeIntervalSince1970: 100)
        let older = ledger.startedAt(of: "a", turnStartedAt: "nope", now: first)
        let newer = ledger.startedAt(of: "b", turnStartedAt: "nope", now: first.addingTimeInterval(30))
        #expect(newer > older)
        #expect(ledger.startedAt(of: "a", turnStartedAt: "nope", now: first.addingTimeInterval(90)) == older)
    }
}
