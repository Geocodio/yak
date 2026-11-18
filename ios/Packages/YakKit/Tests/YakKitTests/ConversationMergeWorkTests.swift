import Foundation
import Testing

@testable import YakKit

private let sendKey = UUID(uuidString: "AAAAAAAA-0000-4000-8000-000000000002")!

private func message(
    uuid: String?, id: String, state: String = "read", sentAt: String = "2026-11-15T09:00:00Z",
    readAfter: Int? = nil, isRetry: Bool = false, text: String = "Text"
) -> ThreadMessage {
    let json: [String: Any?] = [
        "id": id, "uuid": uuid, "text": isRetry ? "" : text, "authorName": "Test person", "authorInitials": "TP",
        "state": state, "sentAt": sentAt, "readAfterSequence": readAfter, "bodyHtml": isRetry ? "" : "<p>Text</p>",
        "isRetry": isRetry,
    ]
    return try! JSONDecoder().decode(
        ThreadMessage.self, from: JSONSerialization.data(withJSONObject: json.mapValues { $0 ?? NSNull() }))
}

private func event(_ key: String, _ sequence: Int) -> ConversationItem {
    ConversationItem(kind: .text, key: key, sequence: sequence)
}

private func milestone(_ key: MilestoneKey, after: Int, run: String = "1") -> ThreadMilestone {
    ThreadMilestone(key: key, label: key.rawValue, tone: .ok, runId: run, afterSequence: after)
}

private func merge(
    messages: [ThreadMessage] = [], items: [ConversationItem] = [], milestones: [ThreadMilestone] = [],
    firstLoadedAfter: Int = 0, delivered: [OutboxDelivery] = [], isLegacy: Bool = false,
    answers: [LegacyAnswer] = []
) -> [ConversationEntry] {
    ConversationMerge.merge(
        messages: messages, readUUIDs: [], items: items, readSequences: [:], messageStates: [:], unsent: [],
        delivered: delivered, milestones: milestones, firstLoadedAfter: firstLoadedAfter, isLegacy: isLegacy,
        legacyAnswers: answers)
}

@Test func aMilestoneSitsAfterItsRunsLastEvent() {
    let entries = merge(
        messages: [message(uuid: "u1", id: "m1", readAfter: 1), message(uuid: "u2", id: "m2", readAfter: 5)],
        items: [event("a", 2), event("b", 3), event("c", 6)],
        milestones: [milestone(.pushed, after: 3), milestone(.ciPassed, after: 3), milestone(.pullRequestOpened, after: 6)])
    #expect(
        entries.map(\.id) == [
            "u1", "a", "b", "milestone:1:pushed:3", "milestone:1:ci_passed:3", "u2", "c",
            "milestone:1:pull_request_opened:6",
        ])
}

@Test func aMilestoneComesBeforeThePersonMessageThatStartsTheNextRun() {
    let entries = merge(
        messages: [message(uuid: "u2", id: "m2", readAfter: 4)], items: [event("a", 3), event("b", 5)],
        milestones: [milestone(.ciFailed, after: 4)])
    #expect(entries.map(\.id) == ["a", "milestone:1:ci_failed:4", "u2", "b"])
}

@Test func aMilestoneBeforeTheLoadedHistoryIsHidden() {
    let milestones = [milestone(.pushed, after: 10), milestone(.ciPassed, after: 25)]
    let items = [event("a", 21), event("b", 30)]
    #expect(merge(items: items, milestones: milestones, firstLoadedAfter: 20).map(\.id) == ["a", "milestone:1:ci_passed:25", "b"])
    #expect(merge(items: items, milestones: milestones, firstLoadedAfter: 0).map(\.id).first == "milestone:1:pushed:10")
    // Loading the older page brings it back, in place.
    #expect(merge(items: [event("z", 5)] + items, milestones: milestones, firstLoadedAfter: 0).count == 5)
}

@Test func aMilestoneForAnEventNotLoadedAtAllStillComesLast() {
    let entries = merge(items: [event("a", 3)], milestones: [milestone(.pushed, after: 99)])
    #expect(entries.map(\.id) == ["a", "milestone:1:pushed:99"])
}

@Test func aRepeatedMilestoneShowsOnce() {
    let entries = merge(items: [event("a", 3)], milestones: [milestone(.pushed, after: 3), milestone(.pushed, after: 3)])
    #expect(entries.count == 2)
}

@Test func aRetryRowHasNoBubbleText() throws {
    let retry = message(uuid: nil, id: "turn-message-9", readAfter: 4, isRetry: true)
    let entries = merge(messages: [retry], items: [event("a", 3), event("b", 6)])
    #expect(entries.map(\.id) == ["a", "turn-message-9", "b"])
    guard case .retry(let row) = entries[1] else {
        Issue.record("a retry is its own row")
        return
    }
    #expect(row.text.isEmpty && row.bodyHtml.isEmpty)
    // A person message stays a bubble.
    let person = merge(messages: [message(uuid: "u1", id: "m1", readAfter: 1)])
    if case .person = person[0] {} else { Issue.record("a person message is a bubble") }
}

@Test func aDrainedQueuedRowKeepsItsPlaceByUUID() {
    let uuid = "00000000-0000-4000-8000-0000000000aa"
    let queued = message(uuid: uuid, id: "queued-message-1", state: "pending", sentAt: "2026-11-15T09:05:00Z")
    let sent = OutboxDelivery(
        sendKey: sendKey, target: .thread("d0e515ef"), status: .queued, messageUUID: uuid, threadID: "d0e515ef",
        text: "Text", sentAt: Date(timeIntervalSince1970: 1_800_000_000))
    let before = merge(messages: [queued], items: [event("a", 3)], delivered: [sent])
    #expect(before.map(\.id) == ["a", uuid])
    // The server takes the note at the start of a turn: same uuid, new id, now read and anchored.
    let taken = message(uuid: uuid, id: "turn-message-7", sentAt: "2026-11-15T09:05:00Z", readAfter: 3)
    let after = merge(messages: [taken], items: [event("a", 3), event("b", 5)], delivered: [sent])
    #expect(after.map(\.id) == ["a", uuid, "b"])
    #expect(!after.contains { if case .delivered = $0 { true } else { false } })
}

@Test func queuedNotesKeepTheirQueueOrderBeforeTheMessageThatStartedTheTurn() {
    let first = message(uuid: "u-q1", id: "turn-message-7", sentAt: "2026-11-15T09:05:00Z", readAfter: 10)
    let second = message(uuid: "u-q2", id: "turn-message-8", sentAt: "2026-11-15T09:06:00Z", readAfter: 10)
    let starter = message(uuid: "u-start", id: "turn-message-9", sentAt: "2026-11-15T09:07:00Z", readAfter: 10)
    let entries = merge(messages: [starter, second, first], items: [event("a", 11)])
    #expect(entries.map(\.id) == ["u-q1", "u-q2", "u-start", "a"])
}

@Test func legacyMilestonesFollowTheirAnswer() {
    let answers = [
        LegacyAnswer(runTaskId: "old-1", html: "<p>One</p>", at: "2026-11-10T10:00:00+00:00"),
        LegacyAnswer(runTaskId: "old-2", html: "<p>Two</p>", at: "2026-11-11T10:00:00+00:00"),
    ]
    let messages = [
        message(uuid: nil, id: "legacy-message-1", sentAt: "2026-11-10T09:00:00+00:00"),
        message(uuid: nil, id: "legacy-message-2", sentAt: "2026-11-11T09:00:00+00:00"),
    ]
    let milestones = [
        milestone(.pullRequestOpened, after: 0, run: "old-1"), milestone(.ciPassed, after: 0, run: "old-2"),
        milestone(.pushed, after: 0, run: "gone"),
    ]
    let entries = merge(messages: messages, milestones: milestones, isLegacy: true, answers: answers)
    #expect(
        entries.map(\.id) == [
            "legacy-message-1", "legacy-answer:old-1", "milestone:old-1:pull_request_opened:0",
            "legacy-message-2", "legacy-answer:old-2", "milestone:old-2:ci_passed:0", "milestone:gone:pushed:0",
        ])
}

@Test func legacyTimesInDifferentWritingsStillOrderByTheMoment() {
    let answers = [LegacyAnswer(runTaskId: "old-1", html: "", at: "2026-11-10T09:30:00Z")]
    let messages = [message(uuid: nil, id: "legacy-message-1", sentAt: "2026-11-10T10:00:00+02:00")]
    // 10:00 at +02:00 is 08:00 UTC, before the answer at 09:30 UTC, though the text sorts after it.
    #expect(merge(messages: messages, isLegacy: true, answers: answers).map(\.id) == ["legacy-message-1", "legacy-answer:old-1"])
}
