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
            .step("Writing the backfill script"), nil, .result("The settings ignore accents today."),
        ])
    #expect(SubThreadRows.make(stack).map(\.style.word) == [.working, .failed, .done])
    func row(_ child: StackChild, canDrive: Bool = true, isRoot: Bool = true, current: Int = 2) -> SubThreadRow {
        SubThreadRows.make(
            ThreadStack(
                rootId: "s7ackr00", isRoot: isRoot, children: [child],
                limit: StackLimit(current: current, max: 4, awake: 1, waiting: 0), maxChildren: 12, models: [],
                usage: StackUsage(turnCount: 0, costUsd: 0, activeHours: 0), messages: [], canDrive: canDrive,
                canStop: true, addChild: AddChildAvailability(allowed: true)))[0]
    }
    func child(
        title: String = "Title", state: StackChildState = .working, step: String? = nil, paused: Bool = false,
        takenOver: Bool = false, pullRequestUrl: String? = nil, repo: String? = nil
    ) -> StackChild {
        StackChild(
            id: "c0000001", name: "the-name", title: title, kind: .implement, state: state, step: step, repo: repo,
            pullRequestUrl: pullRequestUrl, messagesPaused: paused, takenOver: takenOver)
    }
    #expect(row(child(paused: true, takenOver: true)).style.word == .takenOver)
    #expect(row(child(paused: true)).messagesPaused)
    #expect(row(child(state: .queued)).detail == .waitingForSpot(running: 1))
    #expect(row(child(step: "  ")).detail == nil)
    #expect(row(child(title: "  ")).name == "the-name")
    #expect(row(child(repo: "  ")).repo == nil && row(child(repo: "example/web")).repo == "example/web")
    #expect(row(child(pullRequestUrl: "http://x.example.com/1")).pullRequestURL == nil)
    #expect(
        row(child(pullRequestUrl: "https://github.example.com/example/api/pull/41")).pullRequestURL
            == URL(string: "https://github.example.com/example/api/pull/41"))
    // A research sub-thread stored as queued reads as starting, with no reason and no Start now.
    func researchChild(_ state: StackChildState) -> StackChild {
        StackChild(id: "c0000002", name: "r", title: "R", kind: .research, state: state)
    }
    let research = row(researchChild(.queued))
    #expect(research.style.word == .starting && research.detail == nil && research.startNowLimit == nil)
    #expect(row(researchChild(.working)).style.word == .working)
    // "Start now" is for a waiting sub-thread, one limit step up, to a person who can drive the root below the maximum.
    #expect(row(child(state: .queued)).startNowLimit == 3)
    #expect(row(child(state: .working)).startNowLimit == nil)
    #expect(row(child(state: .queued), canDrive: false).startNowLimit == nil)
    #expect(row(child(state: .queued), isRoot: false).startNowLimit == nil)
    #expect(row(child(state: .queued), current: 4).startNowLimit == nil)
}

@Test func theStateOfASubThreadHasOneWordMarkAndToneEverywhere() {
    typealias Style = SubThreadStateStyle
    let words: [(StackChildState, Style.Word, Style.Mark)] = [
        (.starting, .starting, .filled), (.queued, .waiting, .ring), (.working, .working, .filled),
        (.waiting, .needsYou, .filled), (.done, .done, .ring), (.failed, .failed, .filled),
        (.closed, .stopped, .ring),
    ]
    for (state, word, mark) in words {
        let style = Style.of(state)
        #expect(style.word == word && style.mark == mark, "\(state.rawValue)")
    }
    #expect(Style.of(.waiting).tone == .attention && Style.of(.failed).tone == .failure)
    #expect(Style.of(.working).tone == .brand && Style.of(.starting).tone == .brand)
    #expect(Style.of(.queued).tone == .quiet && Style.of(.done).tone == .quiet && Style.of(.closed).tone == .quiet)
    // A state a newer server sends is neutral and is not mistaken for a known one.
    let unknown = Style.of(StackChildState(rawValue: "paused_for_review"))
    #expect(unknown == Style(word: .other, mark: .ring, tone: .quiet))
    // A taken over sub-thread keeps the mark of its state.
    #expect(Style.of(.failed, takenOver: true) == Style(word: .takenOver, mark: .filled, tone: .failure))
    // The drawer's list statuses that mean the same as a stack state read the same.
    #expect(Style.of(listStatus: .running) == Style.of(.working))
    #expect(Style.of(listStatus: .closed) == Style.of(.closed))
    #expect(Style.of(listStatus: .paused) == nil && Style.of(listStatus: .blocked) == nil)
}

@Test func aStartingSubThreadDecodesAsItsOwnState() throws {
    let json = Data(
        """
        {"id":"c1","name":"n","title":"t","kind":"implement","state":"starting","queued":false,
         "messagesPaused":false,"takenOver":false,"canOverride":false}
        """.utf8)
    let child = try JSONDecoder().decode(StackChild.self, from: json)
    #expect(child.state == .starting && SubThreadStateStyle.of(child.state).word == .starting)
}

@Test func theStateScenariosDecodeAndShowEveryState() throws {
    let first = try decoded(ThreadStack.self, "scenario-overview-states-stack")
    #expect(SubThreadRows.make(first).map(\.style.word) == [.working, .waiting, .starting, .done])
    let more = try decoded(ThreadStack.self, "scenario-overview-states-more-stack")
    #expect(SubThreadRows.make(more).map(\.style.word) == [.needsYou, .failed, .stopped])
    let other = try decoded(ThreadStack.self, "scenario-overview-states-other-stack")
    #expect(SubThreadRows.make(other).map(\.style.word) == [.other, .takenOver])
}
