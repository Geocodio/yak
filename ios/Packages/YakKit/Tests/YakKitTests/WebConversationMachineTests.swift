import Foundation
import Testing

@testable import YakKit

typealias Machine = WebConversationMachine

private let start = ContinuousClock.now

private func later(_ seconds: Double) -> ContinuousClock.Instant { start + .seconds(seconds) }

private let ready = EmbedPageMessage.ready(
    threadID: "d0e515ef", path: "/app/embed/threads/d0e515ef", latestSequence: 1, title: "t")

/// A machine that is on its way: minted, redeemed and committed at `start`, so it waits for `ready`.
private func waiting(hasCopy: Bool = false) -> Machine {
    var machine = Machine(supportsEmbeddedThread: true, hasOfflineCopy: hasCopy)
    _ = machine.handle(.begin, at: start)
    _ = machine.handle(.mintSucceeded, at: start)
    _ = machine.handle(.navigation(.committed), at: start)
    return machine
}

private func readyMachine() -> Machine {
    var machine = waiting()
    _ = machine.handle(.page(ready), at: start)
    return machine
}

@Test func anInstanceWithoutWebIsUnsupportedAndDoesNothing() {
    var machine = Machine(supportsEmbeddedThread: false)
    #expect(machine.phase == .unsupported)
    #expect(machine.handle(.begin, at: start) == [])
    #expect(machine.handle(.retry, at: start) == [] && machine.phase == .unsupported)
    #expect(machine.handle(.connectivity(isOnline: true), at: start) == [] && machine.phase == .unsupported)
}

@Test func theHappyPathMintsRedeemsWaitsAndIsReady() {
    var machine = Machine(supportsEmbeddedThread: true)
    #expect(machine.phase == .loading(.minting))
    #expect(machine.handle(.begin, at: start) == [.mint])
    #expect(machine.handle(.mintSucceeded, at: start) == [.redeem])
    #expect(machine.phase == .loading(.redeeming))
    #expect(machine.handle(.navigation(.committed), at: start) == [])
    #expect(machine.phase == .loading(.waitingForReady))
    #expect(machine.handle(.page(ready), at: later(1)) == [])
    #expect(machine.phase == .ready && machine.nextDeadline == nil)
}

@Test func noReadyAfterTheThresholdIsSlowThenFails() {
    var machine = waiting()
    #expect(machine.nextDeadline == later(8))
    _ = machine.handle(.tick, at: later(7))
    #expect(machine.phase == .loading(.waitingForReady))
    _ = machine.handle(.tick, at: later(8))
    #expect(machine.phase == .slow && machine.nextDeadline == later(30))
    _ = machine.handle(.tick, at: later(29))
    #expect(machine.phase == .slow)
    _ = machine.handle(.tick, at: later(30))
    #expect(machine.phase == .failed(.timeout))
}

@Test func aSlowPageThatBecomesReadyIsReady() {
    var machine = waiting()
    _ = machine.handle(.tick, at: later(9))
    _ = machine.handle(.page(ready), at: later(12))
    #expect(machine.phase == .ready)
    _ = machine.handle(.tick, at: later(40))
    #expect(machine.phase == .ready)
}

@Test func aLatePageAfterTheTimeoutRecovers() {
    var machine = waiting()
    _ = machine.handle(.tick, at: later(31))
    #expect(machine.phase == .failed(.timeout))
    _ = machine.handle(.page(ready), at: later(32))
    #expect(machine.phase == .ready)
}

@Test(arguments: [
    (403, Machine.Failure.forbidden), (404, .notFound), (500, .server(500)), (503, .server(503)), (418, .server(418)),
])
func aNavigationStatusMapsToItsFailure(status: Int, failure: Machine.Failure) {
    var machine = Machine(supportsEmbeddedThread: true)
    _ = machine.handle(.begin, at: start)
    _ = machine.handle(.mintSucceeded, at: start)
    #expect(machine.handle(.navigation(.http(status)), at: start) == [])
    #expect(machine.phase == .failed(failure))
}

@Test func aRedirectStatusIsNotAFailure() {
    var machine = Machine(supportsEmbeddedThread: true)
    _ = machine.handle(.begin, at: start)
    _ = machine.handle(.mintSucceeded, at: start)
    _ = machine.handle(.navigation(.http(303)), at: start)
    #expect(machine.phase == .loading(.redeeming))
}

@Test func aFirst401MintsAgainOnceAndTheSecondFails() {
    var machine = Machine(supportsEmbeddedThread: true)
    _ = machine.handle(.begin, at: start)
    _ = machine.handle(.mintSucceeded, at: start)
    #expect(machine.handle(.navigation(.http(401)), at: later(1)) == [.mint])
    #expect(machine.phase == .loading(.minting))
    #expect(machine.handle(.mintSucceeded, at: later(2)) == [.redeem])
    #expect(machine.handle(.navigation(.http(401)), at: later(3)) == [])
    #expect(machine.phase == .failed(.sessionExpired))
}

@Test func aThird401AfterTheGuardIntervalMintsAgain() {
    var machine = Machine(supportsEmbeddedThread: true)
    _ = machine.handle(.begin, at: start)
    _ = machine.handle(.mintSucceeded, at: start)
    _ = machine.handle(.navigation(.http(401)), at: later(1))
    _ = machine.handle(.mintSucceeded, at: later(2))
    #expect(machine.handle(.navigation(.http(401)), at: later(31)) == [.mint])
    #expect(machine.phase == .loading(.minting))
}

@Test func aPageThatSaysAuthExpiredSignsInAgainOnce() {
    var machine = readyMachine()
    #expect(machine.handle(.page(.authExpired), at: later(100)) == [.mint])
    #expect(machine.phase == .signedOut)
    #expect(machine.handle(.mintSucceeded, at: later(101)) == [.redeem])
    #expect(machine.phase == .loading(.redeeming))
    _ = machine.handle(.navigation(.committed), at: later(102))
    _ = machine.handle(.page(ready), at: later(103))
    #expect(machine.phase == .ready)
}

@Test func authExpiredTwiceWithinThirtySecondsIsNotALoop() {
    var machine = readyMachine()
    _ = machine.handle(.page(.authExpired), at: later(100))
    _ = machine.handle(.mintSucceeded, at: later(101))
    _ = machine.handle(.navigation(.committed), at: later(102))
    _ = machine.handle(.page(ready), at: later(103))
    #expect(machine.handle(.page(.authExpired), at: later(110)) == [])
    #expect(machine.phase == .failed(.sessionExpired))
    #expect(machine.phase != .signedOut)
}

@Test func authExpiredAgainAfterThirtySecondsMintsAgain() {
    var machine = readyMachine()
    _ = machine.handle(.page(.authExpired), at: later(100))
    _ = machine.handle(.mintSucceeded, at: later(101))
    _ = machine.handle(.navigation(.committed), at: later(102))
    _ = machine.handle(.page(ready), at: later(103))
    #expect(machine.handle(.page(.authExpired), at: later(131)) == [.mint])
    #expect(machine.phase == .signedOut)
}

@Test func aSpontaneousAuthWhileANewCodeIsMintedIsIgnored() {
    var machine = readyMachine()
    _ = machine.handle(.page(.authExpired), at: later(100))
    #expect(machine.handle(.page(.authExpired), at: later(100.5)) == [])
    #expect(machine.phase == .signedOut)
    _ = machine.handle(.mintSucceeded, at: later(101))
    #expect(machine.handle(.page(.authExpired), at: later(101.5)) == [] && machine.phase == .loading(.redeeming))
}

@Test func authWhileMintingTheFirstCodeIsIgnored() {
    var machine = Machine(supportsEmbeddedThread: true)
    _ = machine.handle(.begin, at: start)
    #expect(machine.handle(.page(.authExpired), at: later(1)) == [] && machine.phase == .loading(.minting))
}

@Test func theRetryButtonStartsFreshAndResetsTheGuard() {
    var machine = readyMachine()
    _ = machine.handle(.page(.authExpired), at: later(100))
    _ = machine.handle(.mintSucceeded, at: later(101))
    _ = machine.handle(.navigation(.http(401)), at: later(102))
    #expect(machine.phase == .failed(.sessionExpired))
    #expect(machine.handle(.retry, at: later(103)) == [.mint])
    _ = machine.handle(.mintSucceeded, at: later(104))
    #expect(machine.handle(.navigation(.http(401)), at: later(105)) == [.mint])
}

@Test func aMintFailureIsAFailureOrTheOfflineCopy() {
    func failed(_ failure: Machine.MintFailure, hasCopy: Bool = false) -> Machine.Phase {
        var machine = Machine(supportsEmbeddedThread: true, hasOfflineCopy: hasCopy)
        _ = machine.handle(.begin, at: start)
        _ = machine.handle(.mintFailed(failure), at: start)
        return machine.phase
    }
    #expect(failed(.network) == .failed(.network))
    #expect(failed(.network, hasCopy: true) == .offline)
    #expect(failed(.unauthorized) == .failed(.deviceUnauthorized))
    #expect(failed(.rejected) == .failed(.mintRejected))
    #expect(failed(.server(502)) == .failed(.server(502)))
}

@Test func aNetworkErrorOnTheNavigationShowsTheCopyWhenThereIsOne() {
    var withCopy = waiting(hasCopy: true)
    _ = withCopy.handle(.navigation(.networkError), at: later(1))
    #expect(withCopy.phase == .offline)
    var without = waiting()
    _ = without.handle(.navigation(.networkError), at: later(1))
    #expect(without.phase == .failed(.network))
}

@Test func aCopyThatAppearsLaterIsUsedForTheNextNetworkError() {
    var machine = waiting()
    _ = machine.handle(.offlineCopyChanged(isAvailable: true), at: start)
    _ = machine.handle(.navigation(.networkError), at: later(1))
    #expect(machine.phase == .offline)
}

@Test func connectivityComingBackReloadsFromOfflineAndFromANetworkFailure() {
    var offline = waiting(hasCopy: true)
    _ = offline.handle(.navigation(.networkError), at: later(1))
    #expect(offline.handle(.connectivity(isOnline: false), at: later(2)) == [] && offline.phase == .offline)
    #expect(offline.handle(.connectivity(isOnline: true), at: later(3)) == [.mint])
    #expect(offline.phase == .loading(.minting))
    var failed = waiting()
    _ = failed.handle(.navigation(.networkError), at: later(1))
    #expect(failed.handle(.connectivity(isOnline: true), at: later(2)) == [.mint])
}

@Test func connectivityDoesNotDisturbAReadyPageOrAServerFailure() {
    var machine = readyMachine()
    #expect(machine.handle(.connectivity(isOnline: false), at: later(1)) == [] && machine.phase == .ready)
    #expect(machine.handle(.connectivity(isOnline: true), at: later(2)) == [] && machine.phase == .ready)
    var failed = waiting()
    _ = failed.handle(.navigation(.http(500)), at: later(1))
    #expect(failed.handle(.connectivity(isOnline: true), at: later(2)) == [] && failed.phase == .failed(.server(500)))
}

@Test func theRetryButtonWorksOnlyForRetryableFailures() {
    var gone = waiting()
    _ = gone.handle(.navigation(.http(404)), at: later(1))
    #expect(gone.handle(.retry, at: later(2)) == [] && gone.phase == .failed(.notFound))
    #expect(!Machine.Failure.notFound.isRetryable && !Machine.Failure.deviceUnauthorized.isRetryable)
    var server = waiting()
    _ = server.handle(.navigation(.http(502)), at: later(1))
    #expect(server.handle(.retry, at: later(2)) == [.mint] && server.phase == .loading(.minting))
    var offline = waiting(hasCopy: true)
    _ = offline.handle(.navigation(.networkError), at: later(1))
    #expect(offline.handle(.retry, at: later(2)) == [.mint])
}

@Test func theRetryClearsTheSlowClock() {
    var machine = waiting()
    _ = machine.handle(.tick, at: later(9))
    #expect(machine.phase == .slow)
    _ = machine.handle(.page(.error(kind: .http, status: 503)), at: later(10))
    #expect(machine.phase == .failed(.server(503)) && machine.nextDeadline == nil)
    _ = machine.handle(.retry, at: later(11))
    #expect(machine.nextDeadline == later(41))
    _ = machine.handle(.tick, at: later(20))
    #expect(machine.phase == .loading(.minting))
}

@Test func pageErrorsMatterOnlyBeforeThePageIsReady() {
    var loading = waiting()
    _ = loading.handle(.page(.error(kind: .network, status: nil)), at: later(1))
    #expect(loading.phase == .failed(.network))
    var script = waiting()
    _ = script.handle(.page(.error(kind: .script, status: nil)), at: later(1))
    #expect(script.phase == .loading(.waitingForReady))
    var clientError = waiting()
    _ = clientError.handle(.page(.error(kind: .http, status: 404)), at: later(1))
    #expect(clientError.phase == .loading(.waitingForReady))
    var live = readyMachine()
    _ = live.handle(.page(.error(kind: .http, status: 503)), at: later(1))
    _ = live.handle(.page(.error(kind: .network, status: nil)), at: later(1))
    #expect(live.phase == .ready)
}

@Test func otherPageMessagesChangeNothing() {
    var machine = readyMachine()
    let messages: [EmbedPageMessage] = [
        .threadChanged(threadID: "a1b2c3d4", previousThreadID: nil), .openPane(.terminal),
        .cardAnswered(card: .question, action: "answer", isOK: true), .unknown(type: "x"),
    ]
    for message in messages { #expect(machine.handle(.page(message), at: later(1)) == []) }
    #expect(machine.phase == .ready)
}

@Test func staleEventsAreIgnored() {
    var machine = Machine(supportsEmbeddedThread: true)
    _ = machine.handle(.begin, at: start)
    #expect(machine.handle(.navigation(.committed), at: start) == [] && machine.phase == .loading(.minting))
    #expect(machine.handle(.navigation(.http(500)), at: start) == [] && machine.phase == .loading(.minting))
    #expect(machine.handle(.page(ready), at: start) == [] && machine.phase == .loading(.minting))
    var ready = readyMachine()
    #expect(ready.handle(.mintSucceeded, at: later(1)) == [] && ready.phase == .ready)
    #expect(ready.handle(.mintFailed(.network), at: later(1)) == [] && ready.phase == .ready)
}

@Test func aReadyPageThatGetsA500OnNavigationFails() {
    var machine = readyMachine()
    _ = machine.handle(.navigation(.http(500)), at: later(1))
    #expect(machine.phase == .failed(.server(500)))
}

private func readyMessage(_ id: String) -> EmbedPageMessage {
    .ready(threadID: id, path: "/app/embed/threads/\(id)", latestSequence: 1, title: "t")
}

private func waitingFor(_ id: String) -> Machine {
    var machine = Machine(supportsEmbeddedThread: true, threadID: id)
    _ = machine.handle(.begin, at: start)
    _ = machine.handle(.mintSucceeded, at: start)
    _ = machine.handle(.navigation(.committed), at: start)
    return machine
}

@Test func aReadyForAnotherThreadIsIgnored() {
    var machine = waitingFor("aaaaaaaa")
    _ = machine.handle(.page(readyMessage("bbbbbbbb")), at: later(1))
    #expect(machine.phase == .loading(.waitingForReady))
    _ = machine.handle(.page(readyMessage("aaaaaaaa")), at: later(2))
    #expect(machine.phase == .ready)
}

@Test func aLateReadyOfThreadADuringTheLoadOfThreadBIsIgnored() {
    var machine = waitingFor("aaaaaaaa")
    _ = machine.handle(.page(readyMessage("aaaaaaaa")), at: later(1))
    #expect(machine.handle(.opened(threadID: "bbbbbbbb"), at: later(2)) == [])
    #expect(machine.phase == .loading(.waitingForReady))
    _ = machine.handle(.page(readyMessage("aaaaaaaa")), at: later(3))
    #expect(machine.phase == .loading(.waitingForReady))
    _ = machine.handle(.page(readyMessage("bbbbbbbb")), at: later(4))
    #expect(machine.phase == .ready && machine.threadID == "bbbbbbbb")
}

@Test func tenThreadSwitchesCauseNoMint() {
    var machine = waitingFor("aaaaaaaa")
    _ = machine.handle(.page(readyMessage("aaaaaaaa")), at: later(1))
    var effects: [Machine.Effect] = []
    for index in 0..<10 {
        let id = String(format: "%08x", index + 1)
        effects += machine.handle(.opened(threadID: id), at: later(Double(2 + index)))
        _ = machine.handle(.page(readyMessage(id)), at: later(Double(2 + index) + 0.5))
        #expect(machine.phase == .ready)
    }
    #expect(effects.isEmpty)
}

@Test func openedWhileLoadingTheRedeemRequestIsIgnoredAndReadyIsRefusedThen() {
    var machine = Machine(supportsEmbeddedThread: true, threadID: "aaaaaaaa")
    _ = machine.handle(.begin, at: start)
    _ = machine.handle(.mintSucceeded, at: start)
    _ = machine.handle(.opened(threadID: "bbbbbbbb"), at: later(1))
    #expect(machine.threadID == "aaaaaaaa")
    _ = machine.handle(.page(readyMessage("aaaaaaaa")), at: later(1))
    #expect(machine.phase == .loading(.redeeming))
}

@Test func theClockRunsFromTheMintAndCoversEveryLoadingPhase() {
    var machine = Machine(supportsEmbeddedThread: true)
    _ = machine.handle(.begin, at: start)
    #expect(machine.nextDeadline == later(30))
    _ = machine.handle(.tick, at: later(30))
    #expect(machine.phase == .failed(.timeout))
    #expect(machine.handle(.mintSucceeded, at: later(31)) == [] && machine.phase == .failed(.timeout))

    var redeeming = Machine(supportsEmbeddedThread: true)
    _ = redeeming.handle(.begin, at: start)
    _ = redeeming.handle(.mintSucceeded, at: later(5))
    #expect(redeeming.nextDeadline == later(30))
    _ = redeeming.handle(.tick, at: later(40))
    #expect(redeeming.phase == .failed(.timeout))
}

@Test func slowAndTimeoutCountFromTheStartOfTheLoadNotFromTheCommit() {
    var machine = Machine(supportsEmbeddedThread: true)
    _ = machine.handle(.begin, at: start)
    _ = machine.handle(.mintSucceeded, at: later(5))
    _ = machine.handle(.navigation(.committed), at: later(6))
    #expect(machine.nextDeadline == later(8))
    _ = machine.handle(.tick, at: later(8))
    #expect(machine.phase == .slow && machine.nextDeadline == later(30))
}

@Test func aSignedOutMintThatNeverAnswersTimesOut() {
    var machine = readyMachine()
    _ = machine.handle(.page(.authExpired), at: later(100))
    #expect(machine.nextDeadline == later(130))
    _ = machine.handle(.tick, at: later(130))
    #expect(machine.phase == .failed(.timeout))
}

@Test func readyRecoversOnlyFromFailuresThatAPageCanFollow() {
    func after(_ status: Int?) -> Machine.Phase {
        var machine = waiting()
        if let status { _ = machine.handle(.navigation(.http(status)), at: later(1)) }
        _ = machine.handle(.page(ready), at: later(2))
        return machine.phase
    }
    #expect(after(500) == .ready)
    #expect(after(403) == .failed(.forbidden))
    #expect(after(404) == .failed(.notFound))
    var network = waiting()
    _ = network.handle(.navigation(.networkError), at: later(1))
    _ = network.handle(.page(ready), at: later(2))
    #expect(network.phase == .ready)
    var unauthorized = Machine(supportsEmbeddedThread: true)
    _ = unauthorized.handle(.begin, at: start)
    _ = unauthorized.handle(.mintFailed(.unauthorized), at: start)
    _ = unauthorized.handle(.page(ready), at: later(1))
    #expect(unauthorized.phase == .failed(.deviceUnauthorized))
    var rejected = Machine(supportsEmbeddedThread: true)
    _ = rejected.handle(.begin, at: start)
    _ = rejected.handle(.mintFailed(.rejected), at: start)
    _ = rejected.handle(.page(ready), at: later(1))
    #expect(rejected.phase == .failed(.mintRejected))
}

@Test func retryWhileSignedOutDoesNotMintTwice() {
    var machine = readyMachine()
    _ = machine.handle(.page(.authExpired), at: later(100))
    #expect(machine.handle(.retry, at: later(101)) == [] && machine.phase == .signedOut)
}
