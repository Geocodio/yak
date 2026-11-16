import Foundation
import Testing

@testable import YakKit

#if canImport(FoundationNetworking)
    import FoundationNetworking
#endif

private let root = "s7ackr00"
private let stackRoute = "GET threads/\(root)/stack"
private let environmentRoute = "GET threads/\(root)/environment"

private func error(_ code: String, _ message: String) -> String {
    #"{"error":"\#(code)","message":"\#(message)","details":{}}"#
}

/// The client retries a `GET` that failed in transit three times, so a scripted failure repeats four times.
private func repeated(_ result: FakeTransport.Result, _ count: Int = 4) -> [FakeTransport.Result] {
    Array(repeating: result, count: count)
}

private func snapshot(children: [String] = []) -> ConversationSnapshot {
    ConversationSnapshot(
        load: .ready,
        entries: children.enumerated().map { index, key in
            .event(ConversationItem(kind: .child, key: "child:\(key)", sequence: index + 1))
        })
}

/// A feed over scripted answers, a clock the test steps by hand, and snapshots and changes the test yields.
private struct FeedRig {
    let feed: OverviewFeed
    let transport: ScriptedTransport
    let clock: ManualClock
    let snapshots: AsyncStream<ConversationSnapshot>.Continuation
    let changes: AsyncStream<OverviewChange>.Continuation
    let actions: OverviewActions
    let states: StreamRecorder<OverviewState>

    init(
        stack: [FakeTransport.Result]? = nil, environment: [FakeTransport.Result]? = nil, holdsStack: Bool = false
    ) async throws {
        let transport = ScriptedTransport()
        let clock = ManualClock()
        let busy = try FakeTransport.Result.scenario(200, "scenario-overview-busy-stack")
        let settled = try FakeTransport.Result.scenario(200, "scenario-overview-environment")
        await transport.on(stackRoute, results: stack ?? [busy])
        await transport.on(environmentRoute, results: environment ?? [settled])
        if holdsStack { await transport.hold(stackRoute) }
        let client = APIClient(
            baseURL: URL(string: "https://example.test")!, token: "test-token", transport: transport,
            appVersion: "1.0", systemDescription: "Test", sleep: { _ in })
        let (snapshotStream, snapshotContinuation) = AsyncStream.makeStream(of: ConversationSnapshot.self)
        let actions = OverviewActions(
            client: client, cache: Cache(instance: UUID(), files: InMemoryFileStore(), clock: clock), clock: clock,
            makeID: { UUID() })
        let changeStream = await actions.changes()
        let (extraChanges, extraContinuation) = AsyncStream.makeStream(of: OverviewChange.self)
        feed = OverviewFeed(
            threadID: root, client: client, clock: clock, snapshots: snapshotStream,
            changes: AsyncStream { continuation in
                Task {
                    for await change in changeStream { continuation.yield(change) }
                    continuation.finish()
                }
                Task {
                    for await change in extraChanges { continuation.yield(change) }
                    continuation.finish()
                }
            })
        self.transport = transport
        self.clock = clock
        self.snapshots = snapshotContinuation
        self.changes = extraContinuation
        self.actions = actions
        states = StreamRecorder(await feed.states())
    }

    func count(_ route: String) async -> Int { await transport.requests(on: route).count }

    func requestCounts() async -> (stack: Int, environment: Int) {
        (await count(stackRoute), await count(environmentRoute))
    }

    func waitForRequests(stack: Int, environment: Int) async {
        await transport.waitForRequests(stack, on: stackRoute)
        await transport.waitForRequests(environment, on: environmentRoute)
    }

    /// Lets every task that can run, run.
    func settle() async {
        for _ in 0..<200 { await Task.yield() }
    }

    /// Waits until `count` sleepers end at `offset` from the start.
    func waitForSleepers(_ count: Int, endingAt offset: Duration) async {
        let deadline = ContinuousClock.now + .seconds(10)
        while clock.sleeperDeadlines.filter({ $0 == offset }).count < count {
            if ContinuousClock.now > deadline {
                Issue.record(
                    "Timed out waiting for \(count) sleepers ending at \(offset); have \(clock.sleeperDeadlines)")
                return
            }
            await Task.yield()
        }
    }

    func advance(to offset: Duration, from start: Duration) async {
        await clock.waitForSleeper(endingAt: offset)
        await clock.advance(by: offset - start)
    }
}

@Test func theFeedPollsOnlyByTheContractsRules() async throws {
    let rig = try await FeedRig()
    await rig.settle()
    #expect(await rig.requestCounts() == (stack: 0, environment: 0))
    await rig.feed.setVisible(true)
    await rig.waitForRequests(stack: 1, environment: 1)
    await rig.waitForSleepers(1, endingAt: .seconds(30))
    await rig.advance(to: .seconds(10), from: .zero)
    await rig.waitForRequests(stack: 2, environment: 1)
    await rig.advance(to: .seconds(20), from: .seconds(10))
    await rig.waitForRequests(stack: 3, environment: 1)
    await rig.waitForSleepers(2, endingAt: .seconds(30))
    await rig.clock.advance(by: .seconds(10))
    await rig.waitForRequests(stack: 4, environment: 2)
    await rig.feed.setVisible(false)
    await rig.clock.advance(by: .seconds(60))
    await rig.settle()
    #expect(await rig.requestCounts() == (stack: 4, environment: 2))
    await rig.feed.close()
}

@Test func aSettingUpRepositoryPollsEveryThreeSeconds() async throws {
    let settingUp = try FakeTransport.Result.scenario(200, "scenario-overview-environment-setting-up")
    let ready = try FakeTransport.Result.scenario(200, "scenario-overview-environment")
    let rig = try await FeedRig(environment: [settingUp, settingUp, ready])
    await rig.feed.setVisible(true)
    await rig.waitForRequests(stack: 1, environment: 1)
    await rig.advance(to: .seconds(3), from: .zero)
    await rig.transport.waitForRequests(2, on: environmentRoute)
    await rig.advance(to: .seconds(6), from: .seconds(3))
    await rig.transport.waitForRequests(3, on: environmentRoute)
    // The answer is ready now, so the next request waits 30 seconds from this one.
    await rig.clock.waitForSleeper(endingAt: .seconds(36))
    #expect(await rig.count(environmentRoute) == 3)
    await rig.feed.close()
}

@Test func childCardsReloadAtMostEveryTwoSeconds() async throws {
    let rig = try await FeedRig()
    await rig.feed.setVisible(true)
    await rig.waitForRequests(stack: 1, environment: 1)
    await rig.clock.waitForSleeper(endingAt: .seconds(10))
    rig.snapshots.yield(snapshot())
    for count in 1...5 { rig.snapshots.yield(snapshot(children: (1...count).map { "c\($0)" })) }
    await rig.transport.waitForRequests(2, on: stackRoute)
    await rig.settle()
    #expect(await rig.count(stackRoute) == 2)
    await rig.advance(to: .seconds(2), from: .zero)
    await rig.transport.waitForRequests(3, on: stackRoute)
    await rig.settle()
    #expect(await rig.count(stackRoute) == 3)
    #expect(await rig.count(environmentRoute) == 1)
    await rig.feed.close()
}

@Test func hiddenEventsLoadOnReturn() async throws {
    let rig = try await FeedRig()
    await rig.feed.setVisible(true)
    await rig.waitForRequests(stack: 1, environment: 1)
    await rig.settle()
    await rig.feed.setVisible(false)
    rig.snapshots.yield(snapshot())
    rig.snapshots.yield(snapshot(children: ["c1"]))
    await rig.settle()
    #expect(await rig.requestCounts() == (stack: 1, environment: 1))
    await rig.feed.setVisible(true)
    await rig.transport.waitForRequests(2, on: stackRoute)
    await rig.settle()
    // The environment loaded moments ago and is waiting out its interval.
    #expect(await rig.requestCounts() == (stack: 2, environment: 1))
    await rig.feed.close()
}

@Test func aReturnToAnOldValueLoadsAtOnce() async throws {
    let rig = try await FeedRig()
    await rig.feed.setVisible(true)
    await rig.waitForRequests(stack: 1, environment: 1)
    await rig.settle()
    await rig.feed.setVisible(false)
    await rig.clock.advance(by: .seconds(15))
    await rig.feed.setVisible(true)
    await rig.transport.waitForRequests(2, on: stackRoute)
    await rig.settle()
    #expect(await rig.requestCounts() == (stack: 2, environment: 1))
    await rig.feed.close()
}

@Test func theStackStatesCoverSlowFailureAndRecovery() async throws {
    let ok = try FakeTransport.Result.scenario(200, "scenario-overview-busy-stack")
    let busy = FakeTransport.Result.json(503, error("unavailable", "m"))
    let offline = FakeTransport.Result.failure(.notConnectedToInternet)
    let rig = try await FeedRig(stack: [ok] + repeated(busy) + repeated(offline) + [ok], holdsStack: true)
    await rig.feed.setVisible(true)
    await rig.transport.waitForRequests(1, on: stackRoute)
    _ = try await rig.states.next { $0.environment.load == .ready }
    await rig.advance(to: .seconds(8), from: .zero)
    let slow = try await rig.states.next { $0.stack.load == .slow }
    #expect(slow.stack.value == nil && slow.environment.load == .ready)
    await rig.transport.release(stackRoute)
    let ready = try await rig.states.next { $0.stack.load == .ready && $0.stack.value != nil }
    #expect(ready.stack.problem == nil && !ready.isOffline)

    await rig.advance(to: .seconds(18), from: .seconds(8))
    let busyState = try await rig.states.next { $0.stack.problem != nil }
    #expect(busyState.stack.problem == .server(code: "unavailable", message: "m"))
    #expect(busyState.stack.value != nil && busyState.stack.load == .ready && !busyState.isOffline)

    await rig.advance(to: .seconds(28), from: .seconds(18))
    let offlineState = try await rig.states.next { $0.isOffline }
    #expect(offlineState.stack.problem == .offline && offlineState.stack.value != nil)

    await rig.advance(to: .seconds(38), from: .seconds(28))
    let recovered = try await rig.states.next { $0.stack.problem == nil && !$0.isOffline }
    #expect(recovered.stack.load == .ready)
    await rig.feed.close()
}

@Test func anEnvironmentFailureWithoutAValueIsAFailedState() async throws {
    let rig = try await FeedRig(environment: [.json(500, error("server_error", "m"))])
    await rig.feed.setVisible(true)
    let failed = try await rig.states.next { $0.environment.load != .loading }
    #expect(failed.environment.load == .failed(.server(code: "server_error", message: "m")))
    #expect(failed.environment.value == nil && !failed.isOffline)
    await rig.feed.close()
}

@Test func aMissingThreadStopsThePolling() async throws {
    let rig = try await FeedRig(stack: [.json(404, error("not_found", "Not found."))])
    await rig.feed.setVisible(true)
    let gone = try await rig.states.next { $0.stack.load == .failed(.notFound) }
    #expect(gone.stack.value == nil)
    await rig.settle()
    await rig.clock.advance(by: .seconds(30))
    await rig.settle()
    #expect(await rig.count(stackRoute) == 1)
    await rig.feed.close()
}

@Test func anActionReloadsItsResource() async throws {
    let rig = try await FeedRig()
    await rig.transport.on("POST threads/q7adapt0/stack/drop", .json(200, #"{"status":"dropped"}"#))
    await rig.transport.on("PUT threads/\(root)/share", .json(200, #"{"mode":"people","userIds":[7]}"#))
    await rig.feed.setVisible(true)
    await rig.waitForRequests(stack: 1, environment: 1)
    _ = try await rig.states.next { $0.stack.value != nil }
    // q7adapt0 is a sub-thread of the loaded stack, so dropping it from anywhere refreshes this Overview.
    #expect(await rig.actions.drop("q7adapt0") == .done)
    await rig.transport.waitForRequests(2, on: stackRoute)
    #expect(await rig.actions.updateShare(root, mode: .people, userIDs: [7]) == .done)
    await rig.settle()
    #expect(await rig.requestCounts() == (stack: 2, environment: 1))
    // A change for a thread that has nothing to do with this one is ignored.
    rig.changes.yield(.stack(threadID: "zzzzzzzz"))
    await rig.settle()
    #expect(await rig.count(stackRoute) == 2)
    await rig.feed.close()
}

@Test func hidingCancelsTheRequestInFlight() async throws {
    let rig = try await FeedRig(holdsStack: true)
    await rig.feed.setVisible(true)
    await rig.transport.waitForRequests(1, on: stackRoute)
    await rig.feed.setVisible(false)
    let deadline = ContinuousClock.now + .seconds(10)
    while await rig.transport.cancelledRequests == 0, ContinuousClock.now < deadline { await Task.yield() }
    #expect(await rig.transport.cancelledRequests == 1)
    await rig.settle()
    let state = try await rig.states.next { _ in true }
    #expect(state.stack.value == nil && !state.isVisible)
    await rig.feed.close()
}

@Test func closingFinishesTheStates() async throws {
    let rig = try await FeedRig()
    let stream = await rig.feed.states()
    await rig.feed.close()
    var last: OverviewState?
    for await state in stream { last = state }
    #expect(last?.isVisible == false)
    await rig.feed.setVisible(true)
    await rig.settle()
    #expect(await rig.requestCounts() == (stack: 0, environment: 0))
}

@Test func aHiddenCallerDoesNotStopTheFeedWhileAnotherIsVisible() async throws {
    let rig = try await FeedRig()
    let pane = UUID()
    let panel = UUID()
    await rig.feed.setVisible(true, caller: pane)
    await rig.waitForRequests(stack: 1, environment: 1)
    await rig.feed.setVisible(true, caller: panel)
    await rig.feed.setVisible(false, caller: pane)
    await rig.waitForSleepers(1, endingAt: .seconds(30))
    await rig.advance(to: .seconds(10), from: .zero)
    await rig.waitForRequests(stack: 2, environment: 1)
    await rig.feed.setVisible(false, caller: panel)
    await rig.clock.advance(by: .seconds(60))
    await rig.settle()
    #expect(await rig.requestCounts() == (stack: 2, environment: 1))
    await rig.feed.close()
}
