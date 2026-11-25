import Foundation
import Testing

@testable import YakKit

private func snapshot(
    children: [String] = [], repositories: [String] = [], sandbox: String? = nil,
    turn: (id: String, state: TurnState)? = nil
) -> ConversationSnapshot {
    var entries: [ConversationEntry] = []
    for (index, key) in children.enumerated() {
        entries.append(.event(ConversationItem(kind: .child, key: "child:\(key)", sequence: index + 1)))
    }
    for (index, key) in repositories.enumerated() {
        entries.append(.event(ConversationItem(kind: .repository, key: "repository:\(key)", sequence: index + 50)))
    }
    return ConversationSnapshot(
        load: .ready, entries: entries, turn: turn.map { TurnChange(turnId: LenientID($0.id), state: $0.state) },
        thread: sandbox.map { ThreadStateChange(status: .open, sandboxState: $0, runnerState: "idle") })
}

@Test func theFirstSnapshotAsksForNothing() {
    #expect(
        OverviewReloads.after(previous: nil, current: snapshot(children: ["a"]), hasSubThreads: true).isEmpty)
}

@Test func aNewChildCardReloadsTheStack() {
    let before = snapshot(children: ["a"])
    #expect(
        OverviewReloads.after(previous: before, current: snapshot(children: ["a", "b"]), hasSubThreads: false) == [
            .stack
        ])
    #expect(OverviewReloads.after(previous: before, current: snapshot(children: ["a"]), hasSubThreads: true).isEmpty)
}

@Test func aSandboxChangeReloadsTheEnvironment() {
    let paused = snapshot(sandbox: "paused")
    #expect(
        OverviewReloads.after(previous: paused, current: snapshot(sandbox: "running"), hasSubThreads: false)
            == [.environment])
    #expect(OverviewReloads.after(previous: paused, current: snapshot(sandbox: "paused"), hasSubThreads: false).isEmpty)
    // A show that finishes loading is not a sandbox change.
    #expect(
        OverviewReloads.after(previous: snapshot(), current: snapshot(sandbox: "running"), hasSubThreads: false).isEmpty
    )
}

@Test func aNewRepositoryCardReloadsTheEnvironment() {
    #expect(
        OverviewReloads.after(
            previous: snapshot(repositories: ["21"]), current: snapshot(repositories: ["21", "22"]),
            hasSubThreads: false) == [.environment])
}

@Test func aTurnChangeReloadsTheStackOnlyWithSubThreads() {
    let running = snapshot(turn: ("41", .running))
    let completed = snapshot(turn: ("41", .completed))
    #expect(OverviewReloads.after(previous: running, current: completed, hasSubThreads: true) == [.stack])
    #expect(OverviewReloads.after(previous: running, current: completed, hasSubThreads: false).isEmpty)
    #expect(
        OverviewReloads.after(previous: running, current: snapshot(turn: ("42", .running)), hasSubThreads: true)
            == [.stack])
}

@Test func aChildCardThatChangesStateReloadsTheStack() {
    func snapshot(state: String) -> ConversationSnapshot {
        var item = ConversationItem(kind: .child, key: "child:q7adapt0", sequence: 1)
        item.child = ChildCard(
            childThreadId: LenientID("q7adapt0"), name: "query-adapter", kind: "implement", state: state,
            messagesPaused: false)
        return ConversationSnapshot(load: .ready, entries: [.event(item)])
    }
    let queued = snapshot(state: "queued")
    #expect(
        OverviewReloads.after(previous: queued, current: snapshot(state: "starting"), hasSubThreads: true) == [.stack])
    #expect(OverviewReloads.after(previous: queued, current: snapshot(state: "queued"), hasSubThreads: true).isEmpty)
}
