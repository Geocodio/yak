import Foundation
import Testing

@testable import YakKit

/// A server whose answers the test gives one by one.
private actor SearchServer {
    private var asked: [String] = []
    private var waiting: [Int: CheckedContinuation<Result<SearchAnswer, APIFailure>, Never>] = [:]
    private(set) var cancelled = 0

    func answer(_ text: String) async -> Result<SearchAnswer, APIFailure> {
        let index = asked.count
        asked.append(text)
        return await withTaskCancellationHandler {
            await withCheckedContinuation { waiting[index] = $0 }
        } onCancel: {
            Task { await self.noteCancelled() }
        }
    }

    private func noteCancelled() { cancelled += 1 }

    var questions: [String] { asked }

    func reply(_ index: Int, _ result: Result<SearchAnswer, APIFailure>) {
        waiting.removeValue(forKey: index)?.resume(returning: result)
    }
}

private func answer(_ ids: String...) -> Result<SearchAnswer, APIFailure> {
    .success(
        SearchAnswer(results: ids.map { SearchResult(kind: .thread, id: $0, title: "T\($0)", subtitle: "") }))
}

private final class Recorder: @unchecked Sendable {
    private let lock = NSLock()
    private var states: [SearchState] = []
    func record(_ state: SearchState) { lock.withLock { states.append(state) } }
    var last: SearchState { lock.withLock { states.last ?? SearchState() } }
}

@MainActor @Suite struct SearchDriverTests {
    private let clock = ManualClock()
    private let server = SearchServer()
    private let recorder = Recorder()
    private let driver: SearchDriver

    init() {
        let server = server
        let recorder = recorder
        driver = SearchDriver(
            clock: clock, debounce: .milliseconds(300),
            perform: { (text: String) async throws(APIFailure) -> SearchAnswer in
                try await server.answer(text).get()
            },
            onChange: { recorder.record($0) })
    }

    private func questions(_ count: Int) async {
        let server = server
        await waitUntil { await server.questions.count >= count }
    }

    @Test func typingWaitsForThePauseAndAsksOnce() async {
        driver.setText("ge")
        driver.setText("geo")
        await clock.waitForSleepers(1)
        #expect(await server.questions.isEmpty)
        await clock.advance(by: .milliseconds(300))
        await questions(1)
        #expect(await server.questions == ["geo"])
        await server.reply(0, answer("a"))
        await waitUntil { recorder.last.phase != .loading }
        #expect(recorder.last.phase == .results([SearchResult(kind: .thread, id: "a", title: "Ta", subtitle: "")]))
    }

    @Test func oneCharacterAsksNothingEvenAfterThePause() async {
        driver.setText("g")
        await clock.advance(by: .seconds(5))
        #expect(await server.questions.isEmpty && recorder.last.phase == .tooShort)
    }

    @Test func changingTheTextCancelsTheRequestInFlight() async {
        driver.setText("geo")
        await clock.waitForSleepers(1)
        await clock.advance(by: .milliseconds(300))
        await questions(1)
        driver.setText("geoc")
        let server = server
        await waitUntil { await server.cancelled == 1 }
        await server.reply(0, answer("old"))
        await clock.waitForSleepers(1)
        await clock.advance(by: .milliseconds(300))
        await questions(2)
        await server.reply(1, answer("new"))
        await waitUntil { recorder.last.phase != .loading }
        guard case .results(let results) = recorder.last.phase else {
            Issue.record("no results")
            return
        }
        #expect(results.map(\.id) == ["new"])
    }

    @Test func clearingTheFieldStopsTheRequestAndShowsTheListAtOnce() async {
        driver.setText("geo")
        await clock.waitForSleepers(1)
        await clock.advance(by: .milliseconds(300))
        await questions(1)
        driver.setText("")
        #expect(recorder.last.phase == .idle)
        await server.reply(0, answer("late"))
        for _ in 0..<50 { await Task.yield() }
        #expect(recorder.last.phase == .idle)
    }

    @Test func aFailureThenARetryAnswers() async {
        driver.setText("geo")
        await clock.waitForSleepers(1)
        await clock.advance(by: .milliseconds(300))
        await questions(1)
        await server.reply(0, .failure(.server(APIError(status: 500, code: "x", message: "", details: [:]))))
        await waitUntil { recorder.last.phase == .failed }
        driver.retry()
        await questions(2)
        await server.reply(1, answer("a"))
        await waitUntil { recorder.last.phase != .loading }
        #expect(recorder.last.phase != .failed && recorder.last.showsServerResults)
    }

    @Test func aRateLimitRetriesByItselfAfterTheWait() async {
        driver.setText("geo")
        await clock.waitForSleepers(1)
        await clock.advance(by: .milliseconds(300))
        await questions(1)
        await server.reply(
            0, .failure(.server(APIError(status: 429, code: "x", message: "", details: [:], retryAfterSeconds: 4))))
        await waitUntil { recorder.last.phase == .rateLimited(retryAfterSeconds: 4) }
        await clock.waitForSleepers(1)
        await clock.advance(by: .seconds(3))
        #expect(await server.questions.count == 1)
        await clock.advance(by: .seconds(1))
        await questions(2)
        await server.reply(1, answer("a"))
        await waitUntil { recorder.last.phase != .loading }
        #expect(recorder.last.showsServerResults)
    }

    @Test func aRateLimitDoesNotRetryAfterTheTextChanged() async {
        driver.setText("geo")
        await clock.waitForSleepers(1)
        await clock.advance(by: .milliseconds(300))
        await questions(1)
        await server.reply(
            0, .failure(.server(APIError(status: 429, code: "x", message: "", details: [:], retryAfterSeconds: 4))))
        await waitUntil { recorder.last.phase == .rateLimited(retryAfterSeconds: 4) }
        driver.setText("g")
        await clock.advance(by: .seconds(10))
        #expect(await server.questions.count == 1 && recorder.last.phase == .tooShort)
    }

    @Test func goingOfflineMidRequestShowsTheLocalFilter() async {
        driver.setText("geo")
        await clock.waitForSleepers(1)
        await clock.advance(by: .milliseconds(300))
        await questions(1)
        driver.setOnline(false)
        #expect(recorder.last.phase == .offline)
        await server.reply(0, answer("late"))
        for _ in 0..<50 { await Task.yield() }
        #expect(recorder.last.phase == .offline)
    }
}
