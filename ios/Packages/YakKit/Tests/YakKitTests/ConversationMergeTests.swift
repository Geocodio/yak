import Foundation
import Testing

@testable import YakKit

private let sendKey = UUID(uuidString: "AAAAAAAA-0000-4000-8000-000000000001")!
private let now = Date(timeIntervalSince1970: 1_800_000_000)
private let outboxID = "outbox:\(sendKey.uuidString.lowercased())"

private func message(
    uuid: String?, id: String = "row", sendKey: String? = nil, state: String = "pending",
    sentAt: String = "2026-11-15T09:00:00Z", readAfter: Int? = nil
) -> ThreadMessage {
    let json: [String: Any?] = [
        "id": id, "uuid": uuid, "sendKey": sendKey, "text": "Text", "authorName": "Test Person",
        "authorInitials": "TP", "model": nil, "state": state, "mode": nil, "intent": nil,
        "sentAt": sentAt, "readAfterSequence": readAfter, "bodyHtml": "<p>Text</p>",
    ]
    let data = try! JSONSerialization.data(withJSONObject: json.mapValues { $0 ?? NSNull() })
    return try! JSONDecoder().decode(ThreadMessage.self, from: data)
}

private func item(_ key: String, _ sequence: Int, kind: ConversationItem.Kind = .text, running: Bool = false)
    -> ConversationItem
{
    var value = ConversationItem(kind: kind, key: key, sequence: sequence)
    value.isRunning = running
    return value
}

private func outboxItem(state: OutboxState = .sending) -> OutboxItem {
    OutboxItem(
        id: sendKey, target: .thread("d0e515ef"), text: "Ship it", model: nil, delivery: .steer,
        attachments: [], state: state, createdAt: now)
}

private func delivery(uuid: String?, status: SendStatus = .sent) -> OutboxDelivery {
    OutboxDelivery(
        sendKey: sendKey, target: .thread("d0e515ef"), status: status, messageUUID: uuid,
        threadID: "d0e515ef", text: "Ship it", sentAt: now)
}

private func turn(_ state: String) -> TurnChange {
    try! JSONDecoder().decode(TurnChange.self, from: Data(#"{"turnId":1,"state":"\#(state)"}"#.utf8))
}

private func merge(
    messages: [ThreadMessage] = [], read: Set<String> = [], items: [ConversationItem] = [],
    sequences: [String: Int] = [:], states: [String: String] = [:], unsent: [OutboxItem] = [],
    delivered: [OutboxDelivery] = []
) -> [ConversationEntry] {
    ConversationMerge.merge(
        messages: messages, readUUIDs: read, items: items, readSequences: sequences, messageStates: states,
        unsent: unsent, delivered: delivered)
}

@Test func oneMessageShowsExactlyOnceThroughDelivery() {
    var entries = merge(unsent: [outboxItem()])
    #expect(entries.map(\.id) == [outboxID])
    let sent = delivery(uuid: "u-9")
    entries = merge(unsent: [outboxItem()], delivered: [sent])
    #expect(entries.map(\.id) == [outboxID])
    if case .delivered = entries[0] {} else { Issue.record("the delivery replaces the outbox copy") }
    entries = merge(messages: [message(uuid: "u-9")], delivered: [sent])
    #expect(entries.map(\.id) == ["u-9"])
    let bySendKey = message(uuid: "u-other", sendKey: sendKey.uuidString.lowercased())
    entries = merge(messages: [bySendKey], unsent: [outboxItem()], delivered: [sent])
    #expect(entries.map(\.id) == ["u-other"])
}

@Test func aSteerSitsWhereClaudeReadIt() throws {
    var state = ThreadEventState()
    state.apply(try EventList.load("events/steer").events)
    let projection = ConversationProjection.project(state)
    let steer = "00000000-0000-4000-8000-000000000002"
    let entries = merge(
        messages: [message(uuid: steer)], read: [steer], items: projection.items,
        sequences: projection.readSequences)
    #expect(entries.map(\.id) == ["tools:msg_3:0", steer, "tools:msg_4:0", "msg_5:0"])
}

@Test func simpleTurnPutsThePromptFirst() throws {
    var state = ThreadEventState()
    state.apply(try EventList.load("events/simple-turn").events)
    let projection = ConversationProjection.project(state)
    let show = try decoded(ThreadShow.self, "scenario-thread-show")
    let uuid = "00000000-0000-4000-8000-000000000001"
    let entries = merge(
        messages: show.messages, read: [uuid], items: projection.items, sequences: projection.readSequences)
    #expect(entries.map(\.id) == [uuid, "msg_1:0", "tools:msg_1:1", "msg_2:0"])
}

@Test func historyComesFirstAndPendingLast() {
    let old = message(uuid: "old", state: "read", sentAt: "2026-11-15T08:00:00Z")
    let waiting = message(uuid: "wait", sentAt: "2026-11-15T10:00:00Z")
    let entries = merge(messages: [waiting, old], items: [item("a", 1)])
    #expect(entries.map(\.id) == ["old", "a", "wait"])
    #expect(entries.last == .person(waiting, isPending: true))
}

@Test func messagesNeverPassEachOther() {
    let first = message(uuid: "A", state: "read", sentAt: "2026-11-15T09:00:00Z", readAfter: 9)
    let second = message(uuid: "B", state: "read", sentAt: "2026-11-15T09:01:00Z", readAfter: 5)
    let entries = merge(messages: [second, first], items: [item("x", 3), item("y", 7)])
    #expect(entries.map(\.id) == ["x", "A", "B", "y"])
}

@Test func aCancelledMessageIsHidden() {
    let cancelled = message(uuid: "c", state: "cancelled")
    let late = message(uuid: "d")
    #expect(merge(messages: [cancelled, late], states: ["d": "cancelled"]).isEmpty)
}

@Test func aReplayedReadNeverTurnsPendingAgain() {
    var tracker = ReadTracker()
    _ = tracker.track(["u1"])
    let known = tracker.track([])
    #expect(known == ["u1"])
    let entries = merge(messages: [message(uuid: "u1")], read: known)
    #expect(entries == [.person(message(uuid: "u1"), isPending: false)])
}

@Test func aStartedMessageIsRead() {
    let entries = merge(messages: [message(uuid: "u1")], states: ["u1": "started"])
    #expect(entries == [.person(message(uuid: "u1"), isPending: false)])
}

@Test func sendKeysMatchWhateverTheCase() {
    let row = message(uuid: "u", sendKey: sendKey.uuidString.uppercased())
    #expect(merge(messages: [row], unsent: [outboxItem()]).map(\.id) == ["u"])
}

@Test func aClearDeliveryMatchesNoRowByUUID() {
    let row = message(uuid: nil, id: "row-1")
    let entries = merge(messages: [row], delivered: [delivery(uuid: nil)])
    #expect(entries.map(\.id) == ["row-1", outboxID])
}

@Test func theCurrentStepIsTheLastRunningGroup() {
    let running = [ConversationEntry.event(item("tools:a", 1, kind: .tools, running: true))]
    #expect(ConversationMerge.currentStepKey(in: running, turn: turn("running")) == "tools:a")
    #expect(ConversationMerge.currentStepKey(in: running, turn: turn("completed")) == nil)
    #expect(ConversationMerge.currentStepKey(in: running, turn: nil) == nil)
    let after = running + [.event(item("t", 2))]
    #expect(ConversationMerge.currentStepKey(in: after, turn: turn("running")) == nil)
}

@Test func deliveredItemsDoNotDuplicateStreamedItems() {
    #expect(merge(items: [item("k", 1), item("k", 1)]).count == 1)
}
