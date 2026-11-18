import Foundation
import Testing

@testable import YakKit

private let route = "GET threads/d0e515ef/pull-requests"

private struct Rig {
    let feed: PullRequestsFeed
    let transport: ScriptedTransport
    let clock: ManualClock
}

private func makeRig() async throws -> Rig {
    let clock = ManualClock()
    let transport = ScriptedTransport()
    await transport.on(route, try .scenario(200, "scenario-pull-requests"))
    let client = APIClient(
        baseURL: URL(string: "https://example.test")!, token: "test-token", transport: transport,
        appVersion: "1.0", systemDescription: "Test", sleep: { _ in })
    let feed = PullRequestsFeed(threadID: "d0e515ef", client: client, clock: clock)
    return Rig(feed: feed, transport: transport, clock: clock)
}

@Test func rowsReadThePanel() throws {
    let rows = PullRequestCardRows.make(try decoded(PullRequestsPanel.self, "scenario-pull-requests"))
    #expect(rows.map(\.id) == [77, 78])
    #expect(rows[0].title == "#41 example/api")
    #expect(rows[0].checks == .passing(2))
    #expect(rows[0].waitsFor == nil)
    #expect(!rows[0].canAskToFix)
    #expect(rows[1].title == "#12 example/web")
    #expect(rows[1].checks == .failing(failed: 1, total: 1, firstExcerpt: "src/uploads.ts:3 unused import"))
    #expect(rows[1].waitsFor == "Merge after example/api #41")
    #expect(rows[1].canAskToFix)
    #expect(rows[1].url == "https://github.com/example/web/pull/12")
}

@Test func aReadOnlyViewerCannotAskToFix() throws {
    let panel = try decoded(PullRequestsPanel.self, "scenario-pull-requests")
    let readOnly = PullRequestsPanel(
        state: panel.state, banner: panel.banner, openCount: panel.openCount, totalCount: panel.totalCount,
        canDrive: false, pullRequests: panel.pullRequests)
    #expect(PullRequestCardRows.make(readOnly).allSatisfy { !$0.canAskToFix })
}

@Test func checksFollowTheStatusWord() {
    func row(_ ci: PullRequestChecks?, number: Int? = nil, mergeAfter: MergeAfter? = nil) -> PullRequestCardRow {
        let pull = PullRequestRow(
            rowId: 1, repo: "example/api", repoInitials: "AP", role: .primary, number: number,
            url: "https://example.test/pull/1", title: "T", state: .open, ci: ci, mergeAfter: mergeAfter)
        return PullRequestCardRows.make(
            PullRequestsPanel(state: .ready, openCount: 1, totalCount: 1, canDrive: true, pullRequests: [pull]))[0]
    }
    #expect(row(nil).checks == .none)
    #expect(row(nil).title == "example/api")
    #expect(
        row(PullRequestChecks(status: .pending, passedChecks: 1, totalChecks: 3, checks: [])).checks
            == .running(done: 1, total: 3))
    #expect(
        row(PullRequestChecks(status: CheckState(rawValue: "skipped"), passedChecks: 0, totalChecks: 0, checks: []))
            .checks == .other("skipped"))
    #expect(row(nil, mergeAfter: MergeAfter(repo: "example/web")).waitsFor == "Merge after example/web")
}

@Test func pollsOnlyWhileVisible() async throws {
    let rig = try await makeRig()
    await rig.feed.setVisible(true)
    await rig.transport.waitForRequests(1, on: route)
    await rig.clock.waitForSleeper(endingAt: .seconds(10))
    await rig.clock.advance(by: .seconds(10))
    await rig.transport.waitForRequests(2, on: route)
    await rig.clock.waitForSleeper(endingAt: .seconds(20))
    await rig.clock.advance(by: .seconds(10))
    await rig.transport.waitForRequests(3, on: route)
    await rig.clock.waitForSleeper(endingAt: .seconds(30))
    await rig.clock.advance(by: .seconds(5))
    await rig.feed.setVisible(false)
    await rig.clock.advance(by: .seconds(30))
    #expect(await rig.transport.requests(on: route).count == 3)
    #expect(rig.clock.sleeperDeadlines.isEmpty)
    await rig.feed.close()
}

@Test func aFailedPollKeepsThePanel() async throws {
    let rig = try await makeRig()
    let snapshots = StreamRecorder(await rig.feed.snapshots())
    await rig.feed.setVisible(true)
    let first = try await snapshots.next { $0.load == .ready }
    #expect(first.panel?.pullRequests.count == 2)
    await rig.transport.on(route, .json(500, #"{"error":"server_error","message":"Failed."}"#))
    await rig.clock.waitForSleeper(endingAt: .seconds(10))
    await rig.clock.advance(by: .seconds(10))
    let failed = try await snapshots.next { $0.problem != nil }
    #expect(failed.load == .ready)
    #expect(failed.panel?.pullRequests.count == 2)
    await rig.transport.on(route, try .scenario(200, "scenario-pull-requests"))
    await rig.clock.waitForSleeper(endingAt: .seconds(20))
    await rig.clock.advance(by: .seconds(10))
    let recovered = try await snapshots.next { $0.problem == nil && $0.panel != nil }
    #expect(recovered.load == .ready)
    await rig.feed.close()
}

@Test func aFirstFailureIsAnErrorAndReloadRecovers() async throws {
    let rig = try await makeRig()
    await rig.transport.on(route, .json(500, #"{"error":"server_error","message":"Failed."}"#))
    let snapshots = StreamRecorder(await rig.feed.snapshots())
    await rig.feed.reload()
    let failed = try await snapshots.next { if case .failed = $0.load { true } else { false } }
    #expect(failed.panel == nil)
    await rig.transport.on(route, try .scenario(200, "scenario-pull-requests"))
    await rig.feed.reload()
    let ready = try await snapshots.next { $0.load == .ready }
    #expect(ready.panel?.openCount == 2)
    await rig.feed.close()
}

@Test func reloadRestartsPollingAfterAProblemThatRetryingCannotFix() async throws {
    let rig = try await makeRig()
    await rig.transport.on(route, .json(404, #"{"error":"not_found","message":"Gone."}"#))
    let snapshots = StreamRecorder(await rig.feed.snapshots())
    await rig.feed.setVisible(true)
    _ = try await snapshots.next { $0.problem != nil }
    await rig.transport.on(route, try .scenario(200, "scenario-pull-requests"))
    await rig.feed.reload()
    _ = try await snapshots.next { $0.load == .ready }
    await rig.clock.waitForSleeper(endingAt: .seconds(10))
    await rig.clock.advance(by: .seconds(10))
    await rig.transport.waitForRequests(3, on: route)
    await rig.feed.close()
}

private func orderRow(_ id: Int, _ state: PullRequestState = .open) -> PullRequestCardRow {
    PullRequestCardRow(id: id, title: "r\(id)", state: state, checks: .none, waitsFor: nil, canAskToFix: false, url: "")
}

@Test func editNeedsTwoOpenRowsAndADriver() {
    let two = [orderRow(1), orderRow(2)]
    #expect(PullRequestOrder.canEdit(canDrive: true, rows: two))
    #expect(!PullRequestOrder.canEdit(canDrive: false, rows: two))
    #expect(!PullRequestOrder.canEdit(canDrive: true, rows: [orderRow(1)]))
    #expect(!PullRequestOrder.canEdit(canDrive: true, rows: [orderRow(1), orderRow(2, .merged), orderRow(3, .closed)]))
}

@Test func onlyOpenRowsMoveAndEndedRowsKeepTheirPlaces() {
    let order = PullRequestOrder.fullOrder(allIDs: [1, 2, 3, 4], openIDs: [1, 3, 4], openOrder: [4, 1, 3])
    #expect(order == [4, 2, 1, 3])
}

@Test func movingAStepStopsAtTheEnds() {
    #expect(PullRequestOrder.moving([1, 2, 3], id: 2, by: -1) == [2, 1, 3])
    #expect(PullRequestOrder.moving([1, 2, 3], id: 1, by: -1) == nil)
    #expect(PullRequestOrder.moving([1, 2, 3], id: 3, by: 1) == nil)
    #expect(PullRequestOrder.moving([1, 2, 3], id: 9, by: 1) == nil)
}

@Test func aDragFollowsOnMoveNumbering() {
    #expect(PullRequestOrder.moving([1, 2, 3], from: IndexSet(integer: 0), to: 3) == [2, 3, 1])
    #expect(PullRequestOrder.moving([1, 2, 3], from: IndexSet(integer: 2), to: 0) == [3, 1, 2])
    #expect(PullRequestOrder.moving([1, 2, 3], from: IndexSet(integer: 0), to: 1) == [1, 2, 3])
}
