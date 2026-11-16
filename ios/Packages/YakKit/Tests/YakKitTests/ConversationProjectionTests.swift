import Foundation
import Testing

@testable import YakKit

private let at = "2026-11-11T00:00:00Z"

private func make(_ sequence: Int, _ type: String, _ data: String) -> ThreadEvent {
    let json = #"{"sequence":\#(sequence),"type":"\#(type)","createdAt":"\#(at)","data":\#(data)}"#
    return try! JSONDecoder().decode(ThreadEvent.self, from: Data(json.utf8))
}

private func assistant(_ sequence: Int, _ messageId: String, _ blocks: String) -> ThreadEvent {
    make(sequence, "entry", #"{"role":"assistant","messageId":"\#(messageId)","blocks":[\#(blocks)]}"#)
}

private func toolResult(_ sequence: Int, _ id: String, _ text: String, isError: Bool = false) -> ThreadEvent {
    make(sequence, "entry", #"{"role":"tool_result","toolUseId":"\#(id)","isError":\#(isError),"text":"\#(text)"}"#)
}

private func use(_ id: String, _ name: String, _ input: String = "{}") -> String {
    #"{"type":"tool_use","id":"\#(id)","name":"\#(name)","input":\#(input)}"#
}

private func text(_ value: String, html: String? = nil) -> String {
    #"{"type":"text","text":"\#(value)"\#(html.map { #","html":"\#($0)""# } ?? "")}"#
}

private func resultEntry(_ sequence: Int, _ value: String) -> ThreadEvent {
    make(
        sequence, "entry",
        #"{"role":"result","subtype":"success","isError":false,"text":"\#(value)","#
            + #""costUsd":0,"durationMs":0,"numTurns":1}"#
    )
}

private func project(_ events: ThreadEvent...) -> ConversationProjection {
    var state = ThreadEventState()
    state.apply(events)
    return ConversationProjection.project(state)
}

@Test func emptyStateProjectsNothing() {
    let projection = ConversationProjection.project(ThreadEventState())
    #expect(projection.items.isEmpty && projection.readSequences.isEmpty)
}

@Test func textAndThinkingBlocksAreKeyedByMessageAndBlockIndex() {
    let items = project(assistant(1, "m1", #"{"type":"thinking","text":"hm"},"# + text("hello"))).items
    #expect(items.map(\.kind) == [.thinking, .text])
    #expect(items.map(\.key) == ["m1:0", "m1:1"])
    #expect(items.map(\.sequence) == [1, 1])
    #expect(items.map(\.text) == ["hm", "hello"])
}

@Test func consecutiveToolUsesBecomeOneToolsItemWithJoinedResults() {
    let items = project(
        assistant(1, "m1", use("a", "Read") + "," + use("b", "Read")), toolResult(2, "a", "file a"),
        toolResult(3, "b", "file b")
    ).items
    #expect(items.count == 1 && items[0].kind == .tools)
    #expect(items[0].key == "tools:m1:0")
    #expect(items[0].text == "Read 2 files")
    #expect(items[0].detail == "file a\n\nfile b")
}

@Test func editsFoldIntoOneEditsItem() {
    let blocks = [
        use("a", "Edit", #"{"file_path":"a.php","old_string":"x","new_string":"y\nz"}"#),
        use("b", "Write", #"{"file_path":"b.php","content":"1\n2\n3"}"#),
        use("c", "MultiEdit", #"{"file_path":"a.php","edits":[{"old_string":"p\nq","new_string":"r"}]}"#),
    ].joined(separator: ",")
    let items = project(assistant(1, "m1", blocks)).items
    #expect(items.count == 1)
    #expect(items[0].kind == .edits && items[0].key == "edits:m1" && items[0].sequence == 1)
    #expect(items[0].files == ["a.php", "b.php"] && items[0].added == 6 && items[0].removed == 3)
}

@Test func everyResultEntryKeepsItsOwnItem() {
    let items = project(resultEntry(4, "one"), resultEntry(7, "two")).items
    #expect(items.map(\.key) == ["result:4", "result:7"])
}

@Test func aUserEntryWithAUUIDOnlyYieldsAReadSequence() {
    let projection = project(
        make(5, "entry", #"{"role":"user","uuid":"u1","kind":"prompt","text":"hi"}"#),
        make(6, "entry", #"{"role":"user","kind":"steer","text":"x"}"#))
    #expect(projection.items.isEmpty)
    #expect(projection.readSequences == ["u1": 5])
}

@Test func streamingTextBecomesATrailingItem() {
    let projection = project(
        make(1, "delta", #"{"blockKey":"m1:0","kind":"text","text":"hel"}"#),
        make(2, "delta", #"{"blockKey":"m1:1","kind":"tool_input","text":"{}"}"#))
    #expect(projection.items.map(\.key) == ["m1:0"])
    #expect(projection.items[0].sequence == 3 && projection.items[0].isStreaming)
    #expect(projection.items[0].text == "hel")
}

@Test func replayYieldsIdenticalKeys() {
    let events = [
        assistant(
            1, "m1",
            text("a") + "," + use("t", "Read") + ","
                + use("e", "Edit", #"{"file_path":"f","old_string":"a","new_string":"b"}"#)),
        toolResult(2, "t", "out"),
    ]
    var state = ThreadEventState()
    state.apply(events)
    let first = ConversationProjection.project(state)
    state.apply(events)
    let redelivered = ConversationProjection.project(state)
    #expect(first.items.map(\.key) == ["m1:0", "tools:m1:1", "edits:m1"])
    #expect(redelivered == first)
}

private func childEvent(_ sequence: Int, _ id: Int, _ state: String, step: String? = nil) -> ThreadEvent {
    let stepJSON = step.map { #""\#($0)""# } ?? "null"
    return make(
        sequence, "child",
        #"{"childThreadId":\#(id),"name":"child-\#(id)","kind":"implement","model":"sonnet","state":"\#(state)","step":\#(stepJSON),"resultSummary":null,"branch":null,"messagesPaused":false}"#
    )
}

private func repositoryEvent(_ sequence: Int, _ repo: String, _ state: String) -> ThreadEvent {
    make(
        sequence, "repository",
        #"{"repo":"\#(repo)","role":"changes","state":"\#(state)","path":"/extras/docs","reason":null,"error":null}"#)
}

@Test func projectsTheLatestChildStateIntoOneCardAtItsFirstPosition() {
    let items = project(
        assistant(1, "m1", text("hi")), childEvent(2, 7, "queued"), assistant(3, "m2", text("later")),
        childEvent(4, 7, "working", step: "Grep")
    ).items
    let card = items.first { $0.kind == .child }
    #expect(card?.key == "child:7" && card?.sequence == 2)
    #expect(card?.child?.state == "working" && card?.child?.step == "Grep")
    #expect(items.sorted { $0.sequence < $1.sequence }.map(\.key) == ["m1:0", "child:7", "m2:0"])
}

@Test func keepsOneCardPerChild() {
    let items = project(childEvent(1, 7, "queued"), childEvent(2, 8, "queued")).items
    #expect(items.map(\.key) == ["child:7", "child:8"])
}

@Test func aLaterRepositoryEventReplacesTheEarlierCard() {
    let items = project(
        assistant(1, "m1", text("hi")), repositoryEvent(2, "example/docs", "running"),
        assistant(3, "m2", text("later")), repositoryEvent(4, "example/docs", "done")
    ).items
    let card = items.first { $0.kind == .repository }
    #expect(card?.key == "repository:example/docs" && card?.sequence == 2)
    #expect(card?.repository?.state == "done")
    #expect(items.sorted { $0.sequence < $1.sequence }.map(\.key) == ["m1:0", "repository:example/docs", "m2:0"])
}

@Test func keepsOneCardPerRepository() {
    let items = project(
        repositoryEvent(1, "example/docs", "running"), repositoryEvent(2, "example/lib", "no_access")
    ).items
    #expect(items.map(\.key) == ["repository:example/docs", "repository:example/lib"])
}

@Test func aToolsItemIsRunningUntilEveryToolUseHasAResult() {
    let open = project(assistant(1, "m1", use("a", "Bash") + "," + use("b", "Bash")), toolResult(2, "a", "ok"))
    #expect(open.items[0].isRunning)
    let done = project(assistant(1, "m1", use("a", "Bash")), toolResult(2, "a", "ok"))
    #expect(!done.items[0].isRunning)
}

@Test func textItemsCarryTheModelFromTheSessionInitEntry() {
    let items = project(
        make(1, "entry", #"{"role":"system","subtype":"init","model":"claude-sonnet-5-5"}"#),
        assistant(2, "m1", text("hi") + #",{"type":"thinking","text":"hm"}"#)
    ).items
    #expect(items.map(\.kind) == [.text, .thinking])
    #expect(items[0].model == "claude-sonnet-5-5" && items[1].model == nil)
}

@Test func aResultThatRepeatsTheFinalAssistantTextIsDropped() {
    let repeated = project(assistant(1, "m1", text("All done. Fixed it.")), resultEntry(2, "All done. Fixed it."))
    let contained = project(assistant(1, "m1", text("Intro\\n\\nAll done.")), resultEntry(2, "All done."))
    let fallback = project(resultEntry(2, "Only the result"))
    #expect(repeated.items.map(\.kind) == [.text])
    #expect(contained.items.map(\.kind) == [.text])
    #expect(fallback.items.map(\.kind) == [.result])
}

@Test func assistantTextCarriesItsServerRenderedHTML() {
    let items = project(assistant(1, "m1", text("## Hi", html: "<h2>Hi</h2>"))).items
    #expect(items[0].html == "<h2>Hi</h2>")
}

@Test func aToolsItemCarriesOneStepPerToolUse() {
    let blocks = [
        use("a", "Read", #"{"file_path":"app/Login.php"}"#), use("b", "Bash", #"{"command":"php artisan test"}"#),
        use("c", "Grep", #"{"pattern":"intended"}"#),
    ].joined(separator: ",")
    let items = project(
        assistant(1, "m1", blocks), toolResult(2, "a", "<?php"), toolResult(3, "b", "Tests: 1 failed", isError: true)
    ).items
    let steps = items[0].steps
    #expect(steps.map(\.category) == [.read, .command, .search])
    #expect(steps.map(\.label) == ["app/Login.php", "php artisan test", "intended"])
    #expect(steps.map(\.isFailed) == [false, true, false])
    #expect(steps.map(\.isRunning) == [false, false, true])
    #expect(steps.map(\.output) == ["<?php", "Tests: 1 failed", nil])
}

@Test func toolOnlyAssistantMessagesMergeIntoOneGroup() {
    var events: [ThreadEvent] = []
    for (index, id) in ["a", "b", "c", "d", "e"].enumerated() {
        let command = use(id, "Bash", #"{"command":"echo \#(id)"}"#)
        events.append(assistant(index + 1, "m\(index)", text("") + "," + command))
    }
    for (index, id) in ["a", "b", "c", "d", "e"].enumerated() { events.append(toolResult(10 + index, id, "ok")) }
    let reads = [use("f", "Read", #"{"file_path":"x.php"}"#), use("g", "Read", #"{"file_path":"y.php"}"#)]
    events.append(assistant(20, "m9", reads.joined(separator: ",")))
    var state = ThreadEventState()
    state.apply(events)
    let items = ConversationProjection.project(state).items
    #expect(items.count == 1 && items[0].steps.count == 7)
    #expect(items[0].text == "Ran 5 commands, Read 2 files")
    #expect(items[0].key == "tools:m0:1" && items[0].isRunning)
}

@Test func aLoneToolCallStaysAOneStepGroup() {
    let items = project(assistant(1, "m1", use("a", "Bash", #"{"command":"ls"}"#)), toolResult(2, "a", "x")).items
    #expect(items.filter { $0.kind == .tools }.count == 1 && items[0].steps.count == 1)
}

@Test func visibleTextSplitsGroups() {
    let items = project(
        assistant(1, "m1", use("a", "Bash")), assistant(2, "m2", use("b", "Bash")),
        assistant(3, "m3", text("Found it")), assistant(4, "m4", use("c", "Bash"))
    ).items
    #expect(items.map(\.kind) == [.tools, .text, .tools])
    #expect(items.map(\.steps.count) == [2, 0, 1])
}

@Test func anEmptyTextBlockDrawsNothingAndAPersonMessageSplitsGroups() {
    let items = project(
        assistant(1, "m1", text("  ") + "," + use("a", "Bash")),
        make(2, "entry", #"{"role":"user","uuid":"u1","text":"hi"}"#), assistant(3, "m2", use("b", "Bash"))
    ).items
    #expect(items.map(\.kind) == [.tools, .tools])
}

@Test func theGroupIsRunningWhileItsLastCallHasNoResult() {
    let items = project(
        assistant(1, "m1", use("a", "Bash")), toolResult(2, "a", "x"), assistant(3, "m2", use("b", "Bash"))
    ).items
    #expect(items.count == 1 && items[0].isRunning)
    #expect(items[0].steps.map(\.isRunning) == [false, true])
}

@Test func projectsSimpleTurn() throws {
    var state = ThreadEventState()
    state.apply(try EventList.load("events/simple-turn").events)
    let projection = ConversationProjection.project(state)
    #expect(projection.items.map(\.key) == ["msg_1:0", "tools:msg_1:1", "msg_2:0"])
    #expect(projection.items[0].model == "claude-sonnet-5-5")
    #expect(projection.items[1].text == "Read 1 file, Ran 1 command" && !projection.items[1].isRunning)
    #expect(projection.readSequences == ["00000000-0000-4000-8000-000000000001": 2])
}

@Test func projectsAFailedTurn() throws {
    var state = ThreadEventState()
    state.apply(try EventList.load("events/failed-turn").events)
    let items = ConversationProjection.project(state).items
    #expect(items.map(\.kind) == [.tools, .result])
    #expect(items[0].steps.map(\.isFailed) == [true])
    #expect(items[1].text == "The migration failed.")
}

@Test func aSteerSplitsTheToolGroups() throws {
    var state = ThreadEventState()
    state.apply(try EventList.load("events/steer").events)
    let projection = ConversationProjection.project(state)
    #expect(projection.items.map(\.key) == ["tools:msg_3:0", "tools:msg_4:0", "msg_5:0"])
    #expect(projection.readSequences == ["00000000-0000-4000-8000-000000000002": 3])
}
