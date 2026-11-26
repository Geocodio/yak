import Foundation
import Testing

@testable import YakKit

private func result(_ id: String, _ kind: SearchResult.Kind = .thread) -> SearchResult {
    SearchResult(kind: kind, id: id, title: "Title \(id)", subtitle: "example/web")
}

/// A machine that has typed `text` and is loading it, and the generation of that question.
private func loading(_ text: String = "geo") -> (machine: SearchMachine, generation: Int) {
    var machine = SearchMachine()
    _ = machine.send(.textChanged(text))
    return (machine, machine.state.generation)
}

@Suite struct SearchMachineTests {
    @Test func startsIdle() {
        let machine = SearchMachine()
        #expect(machine.state.phase == .idle && !machine.state.showsServerResults && !machine.state.filtersLoadedList)
    }

    @Test func oneCharacterAsksNothing() {
        var machine = SearchMachine()
        let effects = machine.send(.textChanged("g"))
        #expect(machine.state.phase == .tooShort && effects == [.cancelPending])
        #expect(machine.state.filtersLoadedList && !machine.state.showsServerResults)
    }

    @Test func spacesDoNotCountAsCharacters() {
        var machine = SearchMachine()
        _ = machine.send(.textChanged(" g "))
        #expect(machine.state.phase == .tooShort && machine.state.query == "g")
        _ = machine.send(.textChanged("   "))
        #expect(machine.state.phase == .idle)
    }

    @Test func twoCharactersWaitThenAsk() {
        var machine = SearchMachine()
        let effects = machine.send(.textChanged("ge"))
        #expect(machine.state.phase == .loading)
        #expect(effects == [.cancelPending, .startDebounce(generation: 1)])
        #expect(machine.send(.debounced(generation: 1)) == [.request(generation: 1, text: "ge")])
    }

    @Test func aPauseThatWasReplacedAsksNothing() {
        var machine = SearchMachine()
        _ = machine.send(.textChanged("ge"))
        _ = machine.send(.textChanged("geo"))
        #expect(machine.send(.debounced(generation: 1)).isEmpty)
        #expect(machine.send(.debounced(generation: 2)) == [.request(generation: 2, text: "geo")])
    }

    @Test func aTrailingSpaceIsTheSameQuestion() {
        var (machine, generation) = loading("geo")
        #expect(machine.send(.textChanged("geo ")).isEmpty)
        #expect(machine.state.generation == generation)
    }

    @Test func anAnswerShowsResultsAndAnnouncesOnce() {
        var (machine, generation) = loading()
        _ = machine.send(.answered(generation: generation, results: [result("a"), result("b")]))
        #expect(machine.state.phase == .results([result("a"), result("b")]))
        #expect(machine.state.announcement == .init(generation: generation, count: 2))
        #expect(machine.state.showsServerResults && !machine.state.filtersLoadedList)
    }

    @Test func noMatchesIsEmptyAndAnnouncesNone() {
        var (machine, generation) = loading()
        _ = machine.send(.answered(generation: generation, results: []))
        #expect(machine.state.phase == .empty && machine.state.announcement?.count == 0)
    }

    @Test func anOlderAnswerNeverShowsOverANewerText() {
        var (machine, first) = loading("geo")
        _ = machine.send(.textChanged("geoc"))
        let second = machine.state.generation
        _ = machine.send(.answered(generation: first, results: [result("old")]))
        #expect(machine.state.phase == .loading && machine.state.announcement == nil)
        _ = machine.send(.answered(generation: second, results: [result("new")]))
        #expect(machine.state.phase == .results([result("new")]))
    }

    @Test func answersArrivingOutOfOrderKeepTheNewest() {
        var (machine, first) = loading("geo")
        _ = machine.send(.debounced(generation: first))
        _ = machine.send(.textChanged("geoc"))
        let second = machine.state.generation
        _ = machine.send(.debounced(generation: second))
        _ = machine.send(.answered(generation: second, results: [result("new")]))
        _ = machine.send(.answered(generation: first, results: [result("old")]))
        #expect(machine.state.phase == .results([result("new")]))
        #expect(machine.state.announcement == .init(generation: second, count: 1))
    }

    @Test func aFailureOfAnOlderQuestionIsDropped() {
        var (machine, first) = loading("geo")
        _ = machine.send(.textChanged("geoc"))
        _ = machine.send(.failed(generation: first, .failed))
        #expect(machine.state.phase == .loading)
    }

    @Test func clearingTheFieldReturnsToTheListAtOnce() {
        var (machine, generation) = loading()
        _ = machine.send(.answered(generation: generation, results: [result("a")]))
        let effects = machine.send(.textChanged(""))
        #expect(machine.state.phase == .idle && effects == [.cancelPending])
        #expect(!machine.state.showsServerResults)
    }

    @Test func clearingWhileLoadingDropsTheLateAnswer() {
        var (machine, generation) = loading()
        _ = machine.send(.textChanged(""))
        _ = machine.send(.answered(generation: generation, results: [result("a")]))
        #expect(machine.state.phase == .idle)
    }

    @Test func typingBackBelowTwoCharactersCancelsTheQuestion() {
        var (machine, generation) = loading("ge")
        let effects = machine.send(.textChanged("g"))
        #expect(effects == [.cancelPending] && machine.state.phase == .tooShort)
        _ = machine.send(.answered(generation: generation, results: [result("a")]))
        #expect(machine.state.phase == .tooShort)
    }

    @Test func aFailureOffersARetryThatAsksAtOnce() {
        var (machine, generation) = loading()
        _ = machine.send(.failed(generation: generation, .failed))
        #expect(machine.state.phase == .failed && machine.state.filtersLoadedList)
        let effects = machine.send(.retry)
        #expect(machine.state.phase == .loading)
        #expect(effects == [.cancelPending, .request(generation: machine.state.generation, text: "geo")])
        #expect(machine.state.generation == generation + 1)
    }

    @Test func aRateLimitSaysHowLongAndRetriesByItself() {
        var (machine, generation) = loading()
        let effects = machine.send(.failed(generation: generation, .rateLimited(retryAfterSeconds: 7)))
        #expect(machine.state.phase == .rateLimited(retryAfterSeconds: 7))
        #expect(effects == [.startWait(generation: generation, seconds: 7)])
        let again = machine.send(.waitOver(generation: generation))
        #expect(machine.state.phase == .loading)
        #expect(again == [.cancelPending, .request(generation: generation + 1, text: "geo")])
    }

    @Test func aRateLimitWithoutAWaitUsesTheDefaultAndClampsTheServers() {
        var (machine, generation) = loading()
        _ = machine.send(.failed(generation: generation, .rateLimited(retryAfterSeconds: nil)))
        #expect(machine.state.phase == .rateLimited(retryAfterSeconds: SearchMachine.defaultWaitSeconds))
        var (other, second) = loading()
        _ = other.send(.failed(generation: second, .rateLimited(retryAfterSeconds: 9_999)))
        #expect(other.state.phase == .rateLimited(retryAfterSeconds: 60))
        var (zero, third) = loading()
        _ = zero.send(.failed(generation: third, .rateLimited(retryAfterSeconds: 0)))
        #expect(zero.state.phase == .rateLimited(retryAfterSeconds: 1))
    }

    @Test func theWaitDoesNothingOnceTheTextChanged() {
        var (machine, generation) = loading()
        _ = machine.send(.failed(generation: generation, .rateLimited(retryAfterSeconds: 5)))
        _ = machine.send(.textChanged("geoc"))
        #expect(machine.send(.waitOver(generation: generation)).isEmpty)
        #expect(machine.state.phase == .loading && machine.state.query == "geoc")
    }

    @Test func theWaitDoesNothingOnceTheFieldIsCleared() {
        var (machine, generation) = loading()
        _ = machine.send(.failed(generation: generation, .rateLimited(retryAfterSeconds: 5)))
        _ = machine.send(.textChanged(""))
        #expect(machine.send(.waitOver(generation: generation)).isEmpty && machine.state.phase == .idle)
    }

    @Test func aRateLimitHasNoManualRetry() {
        var (machine, generation) = loading()
        _ = machine.send(.failed(generation: generation, .rateLimited(retryAfterSeconds: 5)))
        #expect(machine.send(.retry).isEmpty)
    }

    @Test func offlineFiltersTheLoadedListAndRetriesWhenTheConnectionReturns() {
        var (machine, generation) = loading()
        _ = machine.send(.failed(generation: generation, .offline))
        #expect(machine.state.phase == .offline && machine.state.filtersLoadedList)
        let effects = machine.send(.connectivity(isOnline: true))
        #expect(machine.state.phase == .loading)
        #expect(effects == [.cancelPending, .request(generation: generation + 1, text: "geo")])
    }

    @Test func goingOfflineMidRequestDropsTheQuestion() {
        var (machine, generation) = loading()
        _ = machine.send(.debounced(generation: generation))
        let effects = machine.send(.connectivity(isOnline: false))
        #expect(machine.state.phase == .offline && effects == [.cancelPending])
        _ = machine.send(.answered(generation: generation, results: [result("late")]))
        #expect(machine.state.phase == .offline)
    }

    @Test func goingOfflineKeepsResultsThatAreOnScreen() {
        var (machine, generation) = loading()
        _ = machine.send(.answered(generation: generation, results: [result("a")]))
        #expect(machine.send(.connectivity(isOnline: false)).isEmpty)
        #expect(machine.state.phase == .results([result("a")]))
    }

    @Test func typingWhileOfflineAsksNothing() {
        var machine = SearchMachine()
        _ = machine.send(.connectivity(isOnline: false))
        let effects = machine.send(.textChanged("geo"))
        #expect(machine.state.phase == .offline && effects == [.cancelPending])
        let back = machine.send(.connectivity(isOnline: true))
        #expect(machine.state.phase == .loading && back.contains(.request(generation: 2, text: "geo")))
    }

    @Test func comingBackOnlineDoesNothingWithoutASearch() {
        var machine = SearchMachine()
        _ = machine.send(.connectivity(isOnline: false))
        #expect(machine.send(.connectivity(isOnline: true)).isEmpty && machine.state.phase == .idle)
    }

    @Test func aRetryOfAnOfflineSearchAsks() {
        var (machine, generation) = loading()
        _ = machine.send(.failed(generation: generation, .offline))
        #expect(machine.send(.retry) == [.cancelPending, .request(generation: generation + 1, text: "geo")])
    }

    @Test func failuresAreSortedFromTheTransport() {
        #expect(SearchFailure(.network(.notConnectedToInternet)) == .offline)
        #expect(
            SearchFailure(
                .server(APIError(status: 429, code: "too_many", message: "", details: [:], retryAfterSeconds: 12)))
                == .rateLimited(retryAfterSeconds: 12))
        #expect(SearchFailure(.server(APIError(status: 503, code: "x", message: "", details: [:]))) == .offline)
        #expect(SearchFailure(.server(APIError(status: 500, code: "x", message: "", details: [:]))) == .failed)
        #expect(SearchFailure(.notYak) == .failed)
    }
}
