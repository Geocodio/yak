import Foundation
import Testing

@testable import YakKit

private let at = "2026-11-11T00:00:00Z"

private func make(_ sequence: Int, _ type: String, _ data: String) -> ThreadEvent {
    let json = #"{"sequence":\#(sequence),"type":"\#(type)","createdAt":"\#(at)","data":\#(data)}"#
    return try! JSONDecoder().decode(ThreadEvent.self, from: Data(json.utf8))
}

private func user(_ sequence: Int, _ text: String = "hi") -> ThreadEvent {
    make(sequence, "entry", #"{"role":"user","kind":"prompt","text":"\#(text)"}"#)
}

private func delta(_ sequence: Int, _ text: String, _ blockKey: String = "m1:0") -> ThreadEvent {
    make(sequence, "delta", #"{"blockKey":"\#(blockKey)","kind":"text","text":"\#(text)"}"#)
}

private func assistant(_ sequence: Int) -> ThreadEvent {
    make(sequence, "entry", #"{"role":"assistant","messageId":"m1","blocks":[{"type":"text","text":"done"}]}"#)
}

private func child(_ sequence: Int, _ state: String) -> ThreadEvent {
    make(
        sequence, "child",
        #"{"childThreadId":7,"name":"c","kind":"implement","model":null,"state":"\#(state)","step":null,"resultSummary":null,"branch":null,"messagesPaused":false}"#
    )
}

private func turn(_ sequence: Int, _ state: String) -> ThreadEvent {
    make(sequence, "turn", #"{"turnId":7,"state":"\#(state)"}"#)
}

@Test func appliesInOrderEvents() {
    var state = ThreadEventState()
    state.apply([user(1), user(2)])
    #expect(state.entries.count == 2 && state.lastSequence == 2)
}

@Test func dropsDuplicates() {
    var state = ThreadEventState()
    state.apply([user(1), user(2)])
    let applied = state.apply([user(2), user(1)])
    #expect(applied.isEmpty && state.entries.count == 2 && state.lastSequence == 2)
}

@Test func reportsAGapForAnOutOfOrderEventOrPointer() {
    var state = ThreadEventState()
    state.apply([user(1), user(2)])
    #expect(state.gap(before: 3) == nil)
    #expect(state.gap(before: 5) == 2)
}

@Test func accumulatesDeltasUntilTheAssistantEntryClearsThem() {
    var state = ThreadEventState()
    state.apply([delta(1, "he"), delta(2, "llo"), delta(3, "x", "other:0")])
    #expect(state.streamingBlocks.map(\.key) == ["m1:0", "other:0"])
    #expect(state.streamingBlocks[0].text == "hello")
    state.apply([assistant(4)])
    #expect(state.streamingBlocks.map(\.key) == ["other:0"] && state.streamingBlocks[0].text == "x")
    #expect(state.entries.count == 1)
}

@Test func streamResetEmptiesTheStreamingBlocks() {
    var state = ThreadEventState()
    state.apply([delta(1, "he"), make(2, "stream.reset", "{}")])
    #expect(state.streamingBlocks.isEmpty)
}

@Test func keepsToolResultEntriesInOrderAndTracksTurnAndThreadState() {
    var state = ThreadEventState()
    state.apply([
        user(1),
        make(2, "entry", #"{"role":"tool_result","toolUseId":"t","isError":false,"text":"ok"}"#),
        turn(3, "running"),
        make(
            4, "thread",
            #"{"status":"open","sandboxState":"running","runnerState":"ready","blockedReason":null}"#),
    ])
    guard case .user = state.entries[0].entry, case .toolResult = state.entries[1].entry else {
        Issue.record("entry order")
        return
    }
    #expect(state.turn?.turnId.value == "7" && state.turn?.state == .running)
    #expect(state.thread?.status == ThreadStatus(rawValue: "open"))
}

@Test func catchUpLatestSequenceAdvancesLastSequencePastDroppedDeltas() {
    var state = ThreadEventState()
    state.apply([user(1), user(3)], latestSequence: 6)
    #expect(state.lastSequence == 6)
}

@Test func reconnectJourney() {
    var state = ThreadEventState()
    state.apply([user(1), user(2), user(3), user(4)])
    #expect(state.gap(before: 9) == 4)
    state.apply([user(5), delta(6, "a"), user(7), user(8), user(9)], latestSequence: 9)
    #expect(state.lastSequence == 9 && state.entries.count == 8)
    state.apply([user(9), user(10)])
    #expect(state.entries.count == 9 && state.lastSequence == 10)
}

@Test func replacesACardWhenANewerChildEventArrivesAndKeepsItsFirstSequence() {
    var state = ThreadEventState()
    state.apply([child(1, "queued"), child(2, "done")])
    #expect(state.children["7"]?.firstSequence == 1 && state.children["7"]?.data.state == "done")
    #expect(state.lastSequence == 2)
}

@Test func ignoresUnknownEventTypes() {
    var state = ThreadEventState()
    state.apply([make(1, "mystery", "{}")])
    #expect(state.lastSequence == 1 && state.children.isEmpty && state.entries.isEmpty)
    #expect(state.storedEvents.count == 1)
}

@Test func replaysSimpleTurn() throws {
    var state = ThreadEventState()
    _ = state.apply(try EventList.load("events/simple-turn").events)
    #expect(state.lastSequence == 14 && state.entries.count == 7 && state.streamingBlocks.isEmpty)
    #expect(state.turn?.state == .completed && state.messageStates["00000000-0000-4000-8000-000000000001"] == "started")
    #expect(state.storedEvents.count == 11)
}

@Test func aResetDropsTextStreamedBeforeIt() throws {
    var state = ThreadEventState()
    let events = try EventList.load("events/stream-reset").events
    _ = state.apply(Array(events[0..<3]))
    #expect(state.streamingBlocks.isEmpty)
    _ = state.apply(Array(events[3..<5]))
    #expect(state.streamingBlocks.first?.text == "Second try")
    _ = state.apply(Array(events[5...]))
    #expect(state.streamingBlocks.isEmpty && state.entries.count == 1)
}

@Test func aDeltaForAnUnknownBlockStartsThatBlock() {
    var state = ThreadEventState()
    state.apply([delta(1, "x", "msg_x:3")])
    #expect(state.streamingBlocks == [.init(key: "msg_x:3", kind: "text", text: "x")])
}

@Test func questionAndMessageStateAreRecorded() {
    var state = ThreadEventState()
    state.apply([make(1, "question", #"{"open":true}"#), make(2, "message.state", #"{"uuid":"u1","state":"started"}"#)])
    #expect(state.questionOpen == true && state.messageStates["u1"] == "started")
}

@Test func olderPagesNeverOverrideNewerState() {
    var state = ThreadEventState()
    state.apply([user(10), user(11), child(12, "done"), turn(20, "completed")])
    let page = [user(5), user(6), turn(7, "running"), child(8, "queued"), delta(9, "x")]
    state.prependOlder(page)
    #expect(state.turn?.state == .completed && state.lastSequence == 20)
    #expect(state.entries.map(\.sequence) == [5, 6, 10, 11])
    #expect(state.children["7"]?.firstSequence == 8 && state.children["7"]?.data.state == "done")
    #expect(state.storedEvents.map(\.sequence) == [5, 6, 7, 8, 10, 11, 12, 20])
    state.prependOlder(page)
    #expect(state.entries.count == 4 && state.storedEvents.count == 8)
}
