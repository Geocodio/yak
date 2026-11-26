import Foundation
import Testing

@testable import YakKit

private func agents(_ count: Int, stopping: Bool = false) -> RunningAgents {
    RunningAgents(
        runningCount: count,
        running: (0..<count).map {
            RunningAgent(id: "a\($0)", name: "Agent \($0)", state: stopping ? "stopping" : "running")
        })
}

private func line(
    _ bar: RunningAgentsBar, canDrive: Bool = true, isClosed: Bool = false, isOffline: Bool = false
) -> RunningAgentsBarLine? {
    RunningAgentsBarLine.make(bar: bar, canDrive: canDrive, isClosed: isClosed, isOffline: isOffline)
}

// MARK: Decoding

@Test func theServersComposerFixtureCarriesTheRunningAgents() throws {
    let show = try JSONDecoder().decode(ThreadShow.self, from: Fixtures.data("thread-composer"))
    let running = try #require(show.agents)
    #expect(running.runningCount == running.running.count && running.runningCount > 0)
}

@Test func anOlderServerSendsNoAgents() throws {
    #expect(try decoded(ThreadShow.self, "scenario-overview-thread-show").agents == nil)
}

@Test func theStopFixturesDecode() throws {
    let stopping = try JSONDecoder().decode(AgentsStopAnswer.self, from: Fixtures.data("thread-agents-stop"))
    #expect(stopping.status == "stopping" && stopping.stopped == 2)
    #expect(stopping.agents.runningCount == 2 && stopping.agents.isStopping)
    let idle = try JSONDecoder().decode(AgentsStopAnswer.self, from: Fixtures.data("thread-agents-stop-idle"))
    #expect(idle.status == "idle" && idle.agents == RunningAgents(runningCount: 0))
}

@Test func aBrokenAgentRowIsDroppedAndTheCountStays() throws {
    let json = Data(#"{"runningCount":3,"running":[{"id":"a","name":"One","state":"running"},{"name":"no id"}]}"#.utf8)
    let decoded = try JSONDecoder().decode(RunningAgents.self, from: json)
    #expect(decoded.runningCount == 3 && decoded.running.map(\.id) == ["a"])
}

@Test func stopAgentsHasItsOwnRoute() {
    let endpoint = Endpoint.stopAgents("d0e515ef")
    #expect(endpoint.method == .post && endpoint.path == "threads/d0e515ef/agents/stop")
}

// MARK: Which numbers win

@Test func noAgentsHidesTheBar() {
    var bar = RunningAgentsBar()
    #expect(line(bar) == nil)
    bar.showReported(agents(0))
    #expect(line(bar) == nil)
    bar.showReported(nil)
    #expect(line(bar) == nil)
}

@Test func theShowFeedsTheBarUntilThePageReports() {
    var bar = RunningAgentsBar()
    bar.showReported(agents(2))
    #expect(bar.count == 2 && bar.newest == nil && !bar.hasPageReported)
    bar.pageReported(running: 3, newest: "agent-a2")
    #expect(bar.count == 3 && bar.newest == "agent-a2")
    bar.showReported(agents(1))
    #expect(bar.count == 3, "the page wins once it has reported")
    bar.pageReset()
    bar.showReported(agents(1))
    #expect(bar.count == 1 && bar.newest == nil, "a reloaded page hands the numbers back to the show")
}

@Test func anOlderServerLeavesTheBarToThePage() {
    var bar = RunningAgentsBar()
    bar.showReported(nil)
    #expect(line(bar) == nil)
    bar.pageReported(running: 2, newest: nil)
    #expect(line(bar)?.count == 2)
}

@Test func zeroFromThePageHidesTheBarAndNamesNoCard() {
    var bar = RunningAgentsBar()
    bar.pageReported(running: 2, newest: "agent-a")
    bar.pageReported(running: 0, newest: "agent-a")
    #expect(line(bar) == nil && bar.newest == nil)
}

@Test func aNegativeCountIsZero() {
    var bar = RunningAgentsBar()
    bar.pageReported(running: -4, newest: nil)
    #expect(bar.count == 0)
}

@Test func announcesOnlyARealChangeOfAShownBar() {
    #expect(!RunningAgentsBar.announces(from: 0, to: 2), "the bar appearing is read as it is reached")
    #expect(!RunningAgentsBar.announces(from: 2, to: 2))
    #expect(RunningAgentsBar.announces(from: 2, to: 1))
    #expect(RunningAgentsBar.announces(from: 2, to: 0))
}

// MARK: Stopping

@Test func aStopStartsOnceAndTheBarStaysUntilZero() {
    var bar = RunningAgentsBar()
    bar.pageReported(running: 2, newest: "agent-a")
    let started1 = bar.beginStop()
    #expect(started1)
    let started2 = bar.beginStop()
    #expect(!started2, "a second tap while the request runs sends nothing")
    #expect(line(bar)?.isRequesting == true && line(bar)?.stopIsEnabled == false)
    bar.finishStop(AgentsStopResult(outcome: .done, agents: agents(2, stopping: true)))
    #expect(bar.count == 2, "the count is stale until a report says otherwise")
    #expect(line(bar)?.isStopping == true && line(bar)?.stopIsEnabled == false)
    let started3 = bar.beginStop()
    #expect(!started3)
    bar.pageReported(running: 0, newest: nil)
    #expect(line(bar) == nil && bar.phase == .running)
}

@Test func anIdleAnswerHidesTheBarAtOnce() {
    var bar = RunningAgentsBar()
    bar.showReported(agents(1))
    let started4 = bar.beginStop()
    #expect(started4)
    bar.finishStop(AgentsStopResult(outcome: .done, agents: RunningAgents(runningCount: 0)))
    #expect(line(bar) == nil)
}

@Test func newAgentsAfterTheStopAreNotStopping() {
    var bar = RunningAgentsBar()
    bar.pageReported(running: 2, newest: nil)
    _ = bar.beginStop()
    bar.finishStop(AgentsStopResult(outcome: .done, agents: agents(2, stopping: true)))
    bar.pageReported(running: 3, newest: "agent-new")
    #expect(bar.phase == .running && bar.canRequestStop)
}

@Test func theShowCanSayEveryAgentIsStopping() {
    var bar = RunningAgentsBar()
    bar.showReported(agents(2, stopping: true))
    #expect(line(bar)?.isStopping == true)
}

@Test func aStoppingThatNeverEndsOffersStopAllAgain() {
    var bar = RunningAgentsBar()
    bar.pageReported(running: 1, newest: nil)
    _ = bar.beginStop()
    bar.finishStop(AgentsStopResult(outcome: .done))
    bar.stoppingTimedOut()
    #expect(bar.phase == .running && bar.canRequestStop)
}

@Test func aFailureShowsAndTryAgainStopsOnce() {
    var bar = RunningAgentsBar()
    bar.pageReported(running: 2, newest: nil)
    _ = bar.beginStop()
    bar.finishStop(AgentsStopResult(outcome: .failed(.offline)))
    #expect(line(bar)?.failure == .offline && line(bar)?.showsRetry == true && line(bar)?.stopIsEnabled == true)
    let started5 = bar.beginStop()
    #expect(started5)
    #expect(line(bar)?.failure == nil)
}

@Test func aResultThatArrivesWithNoRequestRunningChangesNothing() {
    var bar = RunningAgentsBar()
    bar.pageReported(running: 2, newest: nil)
    bar.finishStop(AgentsStopResult(outcome: .failed(.offline)))
    #expect(bar.phase == .running)
}

// MARK: Errors

@Test func everyFailureMapsToOneKind() {
    func kind(_ outcome: ThreadActionOutcome) -> AgentsStopFailure { AgentsStopFailure(outcome) }
    func refused(_ code: String) -> ThreadActionOutcome { .refused(code: code, message: "x", fieldErrors: [:]) }
    #expect(kind(.failed(.offline)) == .offline, "offline and a timeout")
    #expect(kind(.failed(.needsPairing)) == .signedOut)
    #expect(kind(refused("forbidden")) == .forbidden)
    #expect(kind(.failed(.notFound)) == .unavailable)
    #expect(kind(refused("not_found")) == .unavailable)
    #expect(kind(refused("rate_limited")) == .tooManyRequests)
    #expect(kind(refused("validation_failed")) == .serverProblem, "a 409 or 422")
    #expect(kind(.failed(.server(code: "server_error", message: ""))) == .serverProblem, "a 5xx")
    #expect(kind(.failed(.appTooOld)) == .serverProblem)
    #expect(AgentsStopFailure.allCases.filter(\.isRetryable).count == 3)
}

// MARK: What the bar draws

@Test func aViewerSeesTheCountButNoStopAll() {
    var bar = RunningAgentsBar()
    bar.pageReported(running: 2, newest: "agent-a")
    let viewer = line(bar, canDrive: false)
    #expect(viewer?.count == 2 && viewer?.newest == "agent-a" && viewer?.showsStop == false)
}

@Test func aClosedThreadHidesTheBar() {
    var bar = RunningAgentsBar()
    bar.pageReported(running: 2, newest: nil)
    #expect(line(bar, isClosed: true) == nil)
}

@Test func offlineKeepsTheCountAndDisablesStopAll() {
    var bar = RunningAgentsBar()
    bar.pageReported(running: 2, newest: nil)
    let offline = line(bar, isOffline: true)
    #expect(offline?.count == 2 && offline?.showsStop == true)
    #expect(offline?.stopIsEnabled == false && offline?.needsConnection == true)
}

@Test func aForbiddenAnswerTakesStopAllAway() {
    var bar = RunningAgentsBar()
    bar.pageReported(running: 2, newest: nil)
    _ = bar.beginStop()
    bar.finishStop(AgentsStopResult(outcome: .refused(code: "forbidden", message: "", fieldErrors: [:])))
    #expect(line(bar)?.showsStop == false && line(bar)?.count == 2)
}

// MARK: The engine

private func engineRig() async throws -> (engine: ThreadEngine, transport: ScriptedTransport) {
    let clock = ManualClock()
    let transport = ScriptedTransport()
    await transport.on("GET threads/d0e515ef", try .scenario(200, "scenario-thread-show"))
    await transport.on("POST threads/d0e515ef/seen", .json(200, "{}"))
    await EventServer(threadID: "d0e515ef", events: []).install(on: transport)
    let client = APIClient(
        baseURL: URL(string: "https://example.test")!, token: "test-token", transport: transport,
        appVersion: "1.0", systemDescription: "Test", sleep: { _ in })
    let cache = Cache(instance: UUID(), files: InMemoryFileStore(), clock: clock)
    let engine = ThreadEngine(threadID: "d0e515ef", client: client, cache: cache, clock: clock, frameInterval: .zero)
    await engine.open()
    _ = try await StreamRecorder(await engine.snapshots()).next { $0.load == .ready && !$0.isShowingCachedCopy }
    return (engine, transport)
}

@Test func stopAgentsSendsOnceForADoubleTapAndReturnsTheAgentsThatRemain() async throws {
    let rig = try await engineRig()
    let route = "POST threads/d0e515ef/agents/stop"
    await rig.transport.on(route, .json(200, String(decoding: try Fixtures.data("thread-agents-stop"), as: UTF8.self)))
    await rig.transport.hold(route)
    async let first = rig.engine.stopAgents()
    await rig.transport.waitForRequests(1, on: route)
    async let second = rig.engine.stopAgents()
    for _ in 0..<50 { await Task.yield() }
    await rig.transport.release(route)
    let firstResult = await first
    #expect(firstResult.outcome == .done && firstResult.agents?.runningCount == 2)
    #expect(await second == firstResult)
    #expect(await rig.transport.requests(on: route).count == 1)
}

@Test func stopAgentsMapsTheServersRefusals() async throws {
    let rig = try await engineRig()
    let route = "POST threads/d0e515ef/agents/stop"
    await rig.transport.on(route, .json(403, #"{"error":"forbidden","message":"No."}"#))
    let refused = await rig.engine.stopAgents()
    #expect(AgentsStopFailure(refused.outcome) == .forbidden && refused.agents == nil)
    await rig.transport.on(route, .failure(.timedOut))
    #expect(AgentsStopFailure(await rig.engine.stopAgents().outcome) == .offline)
    await rig.transport.on(route, .json(500, #"{"error":"server_error","message":"Oops"}"#))
    #expect(AgentsStopFailure(await rig.engine.stopAgents().outcome) == .serverProblem)
}
