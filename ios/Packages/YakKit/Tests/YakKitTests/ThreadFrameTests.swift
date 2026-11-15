import Testing

@testable import YakKit

@Test func theDotFollowsTheTurnThenTheRunner() throws {
    let show = try decoded(ThreadShow.self, "scenario-thread-show")
    #expect(ThreadStatusDot.make(nil) == .neutral)
    #expect(ThreadStatusDot.make(ConversationSnapshot(show: show)) == .neutral)
    let running = TurnChange(turnId: LenientID("t1"), state: .running)
    #expect(ThreadStatusDot.make(ConversationSnapshot(show: show, turn: running)) == .running)
    let waiting = TurnChange(turnId: LenientID("t1"), state: .waitingForSandbox)
    #expect(ThreadStatusDot.make(ConversationSnapshot(show: show, turn: waiting)) == .attention)
    let done = TurnChange(turnId: LenientID("t1"), state: .completed)
    #expect(ThreadStatusDot.make(ConversationSnapshot(show: show, turn: done)) == .neutral)
}

@Test func aBlockedThreadNeedsAttention() throws {
    let blocked = try decoded(ThreadShow.self, "scenario-thread-show")
    var thread = blocked.thread
    thread = ThreadDetail(
        id: thread.id, name: thread.name, title: thread.title, repo: thread.repo, kind: thread.kind,
        status: thread.status, visibility: thread.visibility, sandboxState: thread.sandboxState,
        runnerState: thread.runnerState, blockedReason: "usage_limit", pullRequestCount: 0, isOwner: true,
        isStarred: false)
    let show = ThreadShow(
        thread: thread, canDrive: true, messages: [], composer: blocked.composer, panes: blocked.panes,
        latestSequence: 1)
    #expect(ThreadStatusDot.make(ConversationSnapshot(show: show)) == .attention)
}

@Test func everyLoadStateHasOnePresentation() {
    #expect(ThreadLoadPresentation.make(.ready) == .conversation)
    #expect(ThreadLoadPresentation.make(.loading) == .loading)
    #expect(ThreadLoadPresentation.make(.slow) == .slow)
    #expect(ThreadLoadPresentation.make(.failed(.notFound)) == .notFound)
    #expect(ThreadLoadPresentation.make(.failed(.offline)) == .offlineWithoutCopy)
    #expect(
        ThreadLoadPresentation.make(.failed(.server(code: "x", message: "Try later")))
            == .serverError(message: "Try later"))
    for problem in [ThreadProblem.needsPairing, .appTooOld, .serverTooOld] {
        #expect(ThreadLoadPresentation.make(.failed(problem)) == .sessionOwns)
    }
    #expect(ThreadLoadPresentation.slow.offersRetry)
    #expect(ThreadLoadPresentation.serverError(message: "m").offersRetry)
    #expect(!ThreadLoadPresentation.notFound.offersRetry)
    #expect(!ThreadLoadPresentation.offlineWithoutCopy.offersRetry)
}

@Test func anOpenQuestionComesFromTheShow() throws {
    let open = try decoded(ThreadShow.self, "scenario-thread-question")
    #expect(ConversationSnapshot(show: open).hasOpenQuestion)
    #expect(!ConversationSnapshot(show: try decoded(ThreadShow.self, "scenario-thread-show")).hasOpenQuestion)
    #expect(!ConversationSnapshot().hasOpenQuestion)
}
