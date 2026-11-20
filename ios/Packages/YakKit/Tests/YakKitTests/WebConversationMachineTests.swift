import Foundation
import Testing

@testable import YakKit

typealias Machine = WebConversationMachine

private let start = Date(timeIntervalSince1970: 1_800_000_000)

private func later(_ seconds: TimeInterval) -> Date { start.addingTimeInterval(seconds) }

private let ready = EmbedPageMessage.ready(threadID: "d0e515ef", path: "/app/embed/threads/d0e515ef", latestSequence: 1, title: "t")

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
    _ = machine.handle(.tick, at: later(100))
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
