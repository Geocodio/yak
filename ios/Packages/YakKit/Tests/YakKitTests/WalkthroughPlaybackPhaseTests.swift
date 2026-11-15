import Testing

@testable import YakKit

@Suite struct WalkthroughPlaybackPhaseTests {
    private func phase(
        ready: Bool = false, failure: WalkthroughVideoProblem? = nil, online: Bool = true, waited: Duration
    ) -> WalkthroughPlaybackPhase {
        .make(isReady: ready, failure: failure, isOnline: online, waited: waited)
    }

    @Test func startsLoading() { #expect(phase(waited: .zero) == .loading) }

    @Test func slowAtEightSeconds() {
        #expect(phase(waited: .milliseconds(7_900)) == .loading)
        #expect(phase(waited: .seconds(8)) == .slow)
    }

    @Test func failsAtThirty() {
        #expect(phase(waited: .milliseconds(29_900)) == .slow)
        #expect(phase(waited: .seconds(30)) == .failed(.timedOut))
    }

    @Test func offlineBeforeReadyFails() { #expect(phase(online: false, waited: .seconds(1)) == .failed(.offline)) }

    @Test func readyIsPlayingEvenWhenOffline() {
        #expect(phase(ready: true, online: false, waited: .seconds(60)) == .playing)
    }

    @Test func anItemFailureIsShown() {
        #expect(phase(failure: .expired, waited: .seconds(1)) == .failed(.expired))
    }

    private static let url = "NSURLErrorDomain"
    private static let av = "AVFoundationErrorDomain"
    private static let media = "CoreMediaErrorDomain"

    @Test(
        arguments: [
            ([(url, -1009)], nil, WalkthroughVideoProblem.offline),
            ([(url, -1001)], nil, .timedOut),
            ([(av, -11800), (url, -1009)], nil, .offline),
            ([(av, -11800), (media, -12660)], nil, .expired),
            ([(av, -11800), (media, -12938)], nil, .expired),
            ([(av, -11800)], 403, .expired),
            ([(av, -11800)], 404, .expired),
            ([(av, -11800)], 410, .expired),
            ([(av, -11800)], 500, .other),
            ([(av, -11800)], nil, .other),
            ([(av, -11828)], nil, .unsupported),
            ([], nil, .other),
            ([("", Int.min)], nil, .other),
            ([("", Int.max)], nil, .other),
        ] as [([(String, Int)], Int?, WalkthroughVideoProblem)])
    func problemsFromErrorChains(chain: [(String, Int)], status: Int?, expected: WalkthroughVideoProblem) {
        let errors = chain.map { (domain: $0.0, code: $0.1) }
        #expect(WalkthroughVideoProblem.make(errors: errors, httpStatus: status) == expected)
    }

    @Test func onlyTheFirstFourEntriesAreRead() {
        var errors = Array(repeating: (domain: Self.av, code: -11800), count: 100)
        errors[4] = (domain: Self.url, code: -1009)
        #expect(WalkthroughVideoProblem.make(errors: errors, httpStatus: nil) == .other)
    }
}
