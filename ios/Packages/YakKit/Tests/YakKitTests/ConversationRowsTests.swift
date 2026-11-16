import Foundation
import Testing

@testable import YakKit

struct ConversationRowsTests {
    private static let utc = TimeZone(identifier: "UTC")!
    private var calendar: Calendar {
        var calendar = Calendar(identifier: .gregorian)
        calendar.timeZone = Self.utc
        return calendar
    }
    private let now = RelativeAge.parse("2026-11-15T12:00:00Z")!

    private func person(_ id: String, sentAt: String) -> ConversationEntry {
        let json: [String: Any] = [
            "id": id, "uuid": id, "sendKey": NSNull(), "text": "Text", "authorName": "Test Person",
            "authorInitials": "TP", "model": NSNull(), "state": "read", "mode": NSNull(),
            "intent": NSNull(), "sentAt": sentAt, "readAfterSequence": NSNull(), "bodyHtml": "<p>Text</p>",
        ]
        let data = try! JSONSerialization.data(withJSONObject: json)
        return .person(try! JSONDecoder().decode(ThreadMessage.self, from: data), isPending: false)
    }

    private func event(_ key: String, at: String?) -> ConversationEntry {
        var item = ConversationItem(kind: .text, key: key, sequence: 1)
        item.at = at
        return .event(item)
    }

    @Test func dayDividersSplitByCalendarDay() {
        let unsent = OutboxItem(
            id: UUID(uuidString: "AAAAAAAA-0000-4000-8000-000000000001")!, target: .thread("t1"),
            text: "Hi", model: nil, delivery: .steer, attachments: [],
            createdAt: RelativeAge.parse("2026-11-15T11:00:00Z")!)
        let entries = [
            person("m1", sentAt: "2026-11-14T23:50:00Z"), event("e1", at: nil),
            event("e2", at: "2026-11-15T00:10:00Z"), event("e3", at: "2026-11-15T00:20:00Z"), .unsent(unsent),
        ]
        let rows = ConversationRows.make(
            entries: entries, currentStepKey: nil, waiting: nil, now: now, calendar: calendar)
        #expect(
            rows.map(\.id) == [
                "day:2026-11-14", "m1", "e1", "day:2026-11-15", "e2", "e3",
                "outbox:aaaaaaaa-0000-4000-8000-000000000001",
            ])
        #expect(rows[0] == .divider(.yesterday, id: "day:2026-11-14"))
        #expect(rows[3] == .divider(.today, id: "day:2026-11-15"))
    }

    @Test func leadingUndatedEntriesGetNoDividerUntilTheFirstDatedOne() {
        let rows = ConversationRows.make(
            entries: [event("e0", at: nil), event("e1", at: "2026-11-15T01:00:00Z")], currentStepKey: nil,
            waiting: nil, now: now, calendar: calendar)
        #expect(rows.map(\.id) == ["e0", "day:2026-11-15", "e1"])
    }

    @Test func olderDaysUseTheirDate() {
        let rows = ConversationRows.make(
            entries: [person("m1", sentAt: "2026-11-10T09:00:00Z")], currentStepKey: nil, waiting: nil,
            now: now, calendar: calendar)
        #expect(rows[0] == .divider(.date(RelativeAge.parse("2026-11-10T00:00:00Z")!), id: "day:2026-11-10"))
    }

    @Test func marksTheCurrentStepAndEndsWithTheWaitingRow() {
        let rows = ConversationRows.make(
            entries: [event("tools:msg_1:1", at: nil), event("text:2", at: nil)],
            currentStepKey: "tools:msg_1:1", waiting: WaitingRow(isQueued: true), now: now, calendar: calendar)
        guard case .entry(_, let first) = rows[0], case .entry(_, let second) = rows[1] else {
            Issue.record("expected two entries")
            return
        }
        #expect(first && !second)
        #expect(rows.last == .waiting(WaitingRow(isQueued: true)))
        #expect(rows.last?.id == "waiting")
    }
}
