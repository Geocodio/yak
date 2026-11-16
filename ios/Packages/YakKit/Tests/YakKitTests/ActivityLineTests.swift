import Foundation
import Testing

@testable import YakKit

private func toolsItem(steps: [ToolStep], isRunning: Bool = true) -> ConversationItem {
    var item = ConversationItem(kind: .tools, key: "tools:1", sequence: 1)
    item.steps = steps
    item.isRunning = isRunning
    return item
}

private func runningSnapshot(entries: [ConversationEntry], currentStepKey: String?) -> ConversationSnapshot {
    ConversationSnapshot(
        load: .ready, entries: entries, currentStepKey: currentStepKey,
        turn: TurnChange(turnId: LenientID("t1"), state: .running))
}

@Test func aQuestionWinsOverWork() throws {
    var show: ThreadShow = try decoded(ThreadShow.self, "scenario-thread-question")
    #expect(show.question != nil)
    var snapshot = runningSnapshot(entries: [], currentStepKey: nil)
    snapshot.show = show
    #expect(ActivityLine.make(snapshot) == .question(count: 2))
    show.question = nil
    snapshot.show = show
    #expect(ActivityLine.make(snapshot) == .working)
}

@Test func theCurrentStepIsShown() {
    let steps = [
        ToolStep(
            key: "tool:a", category: .read, tool: "Read", label: "Package.swift", isFailed: false,
            isRunning: false),
        ToolStep(
            key: "tool:b", category: .command, tool: "Bash", label: "swift test", isFailed: false,
            isRunning: true),
    ]
    let snapshot = runningSnapshot(entries: [.event(toolsItem(steps: steps))], currentStepKey: "tools:1")
    #expect(ActivityLine.make(snapshot) == .step(category: .command, label: "swift test", isFailed: false))
}

@Test func withNoRunningStepTheLastStepIsShown() {
    let steps = [
        ToolStep(
            key: "tool:a", category: .read, tool: "Read", label: "Package.swift", isFailed: true,
            isRunning: false)
    ]
    let snapshot = runningSnapshot(entries: [.event(toolsItem(steps: steps))], currentStepKey: "tools:1")
    #expect(ActivityLine.make(snapshot) == .step(category: .read, label: "Package.swift", isFailed: true))
}

@Test func aRunningTurnWithoutAStepIsWorking() throws {
    #expect(ActivityLine.make(runningSnapshot(entries: [], currentStepKey: nil)) == .working)
    #expect(ActivityLine.make(runningSnapshot(entries: [], currentStepKey: "missing")) == .working)
    var composerOnly = ConversationSnapshot(load: .ready)
    composerOnly.turn = TurnChange(turnId: LenientID("t1"), state: .pending)
    #expect(ActivityLine.make(composerOnly) == .working)
}

@Test func nothingRunsNothingShows() throws {
    #expect(ActivityLine.make(nil) == nil)
    var snapshot = runningSnapshot(entries: [], currentStepKey: nil)
    snapshot.turn = TurnChange(turnId: LenientID("t1"), state: .completed)
    #expect(ActivityLine.make(snapshot) == nil)
}
