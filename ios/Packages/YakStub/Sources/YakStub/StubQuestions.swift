import Foundation

/// The answers to a thread's clarification questions. `question-open` (core) takes them; these scenarios play it
/// underneath and change what the answer route says: the round was closed elsewhere, or the first send fails.
/// The last request body is kept so a test can prove what the app sent.
final class StubQuestions: StubFeature, @unchecked Sendable {
    let name = "question"
    let scenarios = ["question-closed", "question-retry"]

    private let lock = NSLock()
    private var sends = 0
    private var lastBody = ""

    func coreScenario(for scenario: String) -> String? { Scenario.questionOpen.rawValue }

    func reset() {
        lock.withLock {
            sends = 0
            lastBody = ""
        }
    }

    func control(_ request: StubRequest) -> StubReply? {
        guard request.path == "/__stub/question/last-request" else { return nil }
        let body = lock.withLock { lastBody }
        return StubReply(answer: .reply(status: 200, contentType: "text/plain", body: Data(body.utf8)))
    }

    func answer(route: String, request: StubRequest, context: StubContext) -> StubReply? {
        guard route.hasPrefix("POST threads/"), route.hasSuffix("/clarification-answers"),
            scenarios.contains(context.scenario)
        else { return nil }
        let count = lock.withLock { () -> Int in
            sends += 1
            lastBody = String(decoding: request.body, as: UTF8.self)
            return sends
        }
        if context.scenario == "question-closed" {
            // The round was answered elsewhere: the thread no longer asks, and this answer is refused.
            _ = context.core()
            return StubReply(
                answer: Scenario.error(409, "question_closed", "These questions were already answered."))
        }
        if count == 1 {
            return StubReply(
                answer: Scenario.error(500, "server_error", "Yak could not handle this request. Try again."))
        }
        return StubReply(answer: context.core())
    }
}
