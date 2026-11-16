import Foundation
import Testing

@testable import YakKit

private let approveRoute = "POST tasks/k3m9p2qa/co-owner/approve"
private let feedbackRoute = "POST tasks/k3m9p2qa/co-owner/request-changes"
private let rejectRoute = "POST tasks/k3m9p2qa/co-owner/reject"
private let showRoute = "GET tasks/k3m9p2qa"

private func makeFeed(_ transport: ScriptedTransport) async throws -> TaskDetailFeed {
    await transport.on(showRoute, try .scenario(200, "scenario-task"))
    let client = APIClient(
        baseURL: URL(string: "https://example.test")!, token: "test-token", transport: transport,
        appVersion: "1.0", systemDescription: "Test", sleep: { _ in })
    let clock = ManualClock()
    return TaskDetailFeed(
        taskID: "k3m9p2qa", client: client, cache: Cache(instance: UUID(), files: InMemoryFileStore(), clock: clock),
        clock: clock)
}

@Suite struct TaskActionsTests {
    @Test func approveWaitsLongAndNeverResends() async throws {
        let transport = ScriptedTransport()
        let feed = try await makeFeed(transport)
        await transport.on(approveRoute, .failure(.timedOut))
        let outcome = await feed.perform(.approve)
        #expect(outcome == .failed(.offline))
        #expect(await transport.requests(on: approveRoute).count == 1)
        #expect(await transport.requests(on: showRoute).count == 1)
        let request = try #require(await transport.requests(on: approveRoute).first)
        #expect(request.timeoutInterval == 60)
        let routes = await transport.requests.map { "\($0.httpMethod ?? "") \($0.url?.lastPathComponent ?? "")" }
        #expect(routes == ["POST approve", "GET k3m9p2qa"])
    }

    @Test func aDoubleTapDecidesOnce() async throws {
        let transport = ScriptedTransport()
        let feed = try await makeFeed(transport)
        await transport.on(approveRoute, .json(200, #"{"status":"approved"}"#))
        await transport.hold(approveRoute)
        async let first = feed.perform(.approve)
        await transport.waitForRequests(1, on: approveRoute)
        async let second = feed.perform(.approve)
        var attempts = 0
        while await feed.joinedCallCount < 1 && attempts < 10_000 {
            attempts += 1
            await Task.yield()
        }
        if attempts == 10_000 { Issue.record("the second tap never joined the request in flight") }
        await transport.release(approveRoute)
        #expect(await first == .done("approved"))
        #expect(await second == .done("approved"))
        #expect(await transport.requests(on: approveRoute).count == 1)
    }

    @Test func aRefusedFeedbackKeepsItsField() async throws {
        let transport = ScriptedTransport()
        let feed = try await makeFeed(transport)
        await transport.on(
            feedbackRoute,
            .json(
                422,
                #"{"error":"validation_failed","message":"Invalid.","details":{"errors":{"feedback":["Say what to change."]}}}"#
            ))
        let outcome = await feed.perform(.requestChanges(feedback: "  "))
        guard case .refused(let code, _, let fieldErrors, _) = outcome else {
            Issue.record("expected a refusal, got \(outcome)")
            return
        }
        #expect(code == "validation_failed")
        #expect(fieldErrors["feedback"] == ["Say what to change."])
        #expect(await transport.requests(on: feedbackRoute).count == 1)
        #expect(await transport.requests(on: showRoute).count == 1)
    }

    @Test func aRefusalAfterTheViewersOwnDecisionShowsTheDecidedState() async throws {
        let transport = ScriptedTransport()
        let feed = try await makeFeed(transport)
        await transport.on(rejectRoute, .json(409, #"{"error":"already_resolved","message":"Done."}"#))
        var object = try #require(
            try JSONSerialization.jsonObject(with: Scenarios.data("scenario-task")) as? [String: Any])
        var panel = object["coOwner"] as? [String: Any] ?? [:]
        panel["status"] = "rejected"
        panel["decidedBy"] = "Test person"
        object["coOwner"] = panel
        let decided = String(decoding: try JSONSerialization.data(withJSONObject: object), as: UTF8.self)
        await transport.on(showRoute, .json(200, decided))
        guard case .refused(let code, _, _, _) = await feed.perform(.reject) else {
            Issue.record("expected a refusal")
            return
        }
        #expect(code == "already_resolved")
        #expect(await transport.requests(on: rejectRoute).count == 1)
        #expect(await transport.requests(on: showRoute).count == 1)
        let snapshot = try await StreamRecorder(await feed.snapshots()).next { _ in true }
        let task = try #require(snapshot.task)
        #expect(TaskDecisionState.make(task) == .decided(status: "rejected", by: "Test person"))
    }

    @Test func aDifferentActionWaitsForTheOneInFlight() async throws {
        let transport = ScriptedTransport()
        let feed = try await makeFeed(transport)
        await transport.on(approveRoute, .json(200, #"{"status":"approved"}"#))
        await transport.on(rejectRoute, .json(409, #"{"error":"already_resolved","message":"Done."}"#))
        await transport.hold(approveRoute)
        async let approve = feed.perform(.approve)
        await transport.waitForRequests(1, on: approveRoute)
        async let reject = feed.perform(.reject)
        for _ in 0..<50 { await Task.yield() }
        #expect(await transport.requests(on: rejectRoute).isEmpty)
        await transport.release(approveRoute)
        #expect(await approve == .done("approved"))
        guard case .refused(let code, _, _, _) = await reject else {
            Issue.record("expected a refusal")
            return
        }
        #expect(code == "already_resolved")
    }
}
