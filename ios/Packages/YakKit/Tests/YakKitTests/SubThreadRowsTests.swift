import Foundation
import Testing

@testable import YakKit

@Test func overrideActionsOnlyWhereTheStackSaysSo() throws {
    let stack = try decoded(ThreadStack.self, "scenario-overview-busy-stack")
    #expect(SubThreadRows.make(stack).map(\.actions) == [[.open], [.open, .takeOver, .split, .drop], [.open]])
    let now = Date(timeIntervalSince1970: 1_800_000_000)
    #expect(
        SubThreadActions.forDrawerRow(
            childID: "q7adapt0", stack: stack, fetchedAt: now.addingTimeInterval(-5), now: now)
            == .ready([.open, .takeOver, .split, .drop]))
    #expect(
        SubThreadActions.forDrawerRow(
            childID: "q7adapt0", stack: stack, fetchedAt: now.addingTimeInterval(-10), now: now) == .loading)
    #expect(SubThreadActions.forDrawerRow(childID: "q7adapt0", stack: nil, fetchedAt: nil, now: now) == .loading)
    #expect(
        SubThreadActions.forDrawerRow(childID: "q7adapt0", stack: stack, fetchedAt: nil, now: now) == .loading)
    #expect(
        SubThreadActions.forDrawerRow(childID: "gone0000", stack: stack, fetchedAt: now, now: now)
            == .ready([.open]))
    let recipient = try decoded(ThreadStack.self, "scenario-overview-recipient-stack")
    #expect(
        SubThreadActions.forDrawerRow(childID: "q7adapt0", stack: recipient, fetchedAt: now, now: now)
            == .ready([.open]))
}

@Test func rowDetailsInOrder() throws {
    let stack = try decoded(ThreadStack.self, "scenario-overview-busy-stack")
    #expect(
        SubThreadRows.make(stack).map(\.detail) == [
            .step("Writing the backfill script"), .state(.failed), .result("The settings ignore accents today."),
        ])
    func row(_ child: StackChild) -> SubThreadRow {
        SubThreadRows.make(
            ThreadStack(
                rootId: "s7ackr00", isRoot: true, children: [child],
                limit: StackLimit(current: 1, max: 4, awake: 1, waiting: 0), maxChildren: 12, models: [],
                usage: StackUsage(turnCount: 0, costUsd: 0, activeHours: 0), messages: [], canDrive: true,
                canStop: true, addChild: AddChildAvailability(allowed: true)))[0]
    }
    func child(
        title: String = "Title", state: StackChildState = .working, step: String? = nil, queued: Bool = false,
        paused: Bool = false, takenOver: Bool = false, pullRequestUrl: String? = nil
    ) -> StackChild {
        StackChild(
            id: "c0000001", name: "the-name", title: title, kind: .research, state: state, step: step,
            pullRequestUrl: pullRequestUrl, queued: queued, messagesPaused: paused, takenOver: takenOver)
    }
    #expect(row(child(paused: true, takenOver: true)).detail == .takenOver)
    #expect(row(child(paused: true)).detail == .messagesPaused)
    #expect(row(child(queued: true)).detail == .queued)
    #expect(row(child(step: "  ")).detail == .state(.working))
    #expect(row(child(title: "  ")).title == "the-name")
    #expect(row(child(pullRequestUrl: "http://x.example.com/1")).pullRequestURL == nil)
    #expect(
        row(child(pullRequestUrl: "https://github.example.com/example/api/pull/41")).pullRequestURL
            == URL(string: "https://github.example.com/example/api/pull/41"))
}
