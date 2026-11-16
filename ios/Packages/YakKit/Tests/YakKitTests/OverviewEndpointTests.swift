import Foundation
import Testing

@testable import YakKit

#if canImport(FoundationNetworking)
    import FoundationNetworking
#endif

private let sendKey = UUID(uuidString: "11111111-2222-3333-4444-555555555555")!

private func makeClient(_ transport: any HTTPTransport) -> APIClient {
    APIClient(
        baseURL: URL(string: "https://yak.example.com")!, token: "yakdev_abc", transport: transport,
        appVersion: "1.0.0", systemDescription: "27.2; iPhone19,4", sleep: { _ in })
}

private func jsonBody<R>(_ endpoint: Endpoint<R>) throws -> NSDictionary {
    let body = try #require(endpoint.body)
    let data = try JSONEncoder().encode(body)
    return try #require(try JSONSerialization.jsonObject(with: data) as? NSDictionary)
}

@Test func buildsEveryOverviewRequest() throws {
    let add = Endpoint<SubThreadCreated>.addSubThread(
        "s7ackr00", name: "check-the-cache", brief: "Look at the cache keys.", kind: .research, model: nil,
        sendKey: sendKey)
    #expect(add.method == .post && add.path == "threads/s7ackr00/stack/children")
    #expect(
        try jsonBody(add) == [
            "name": "check-the-cache", "brief": "Look at the cache keys.", "kind": "research",
            "model": NSNull(), "send_key": sendKey.uuidString,
        ])
    let pinned = Endpoint<SubThreadCreated>.addSubThread(
        "s7ackr00", name: "n", brief: "b", kind: .implement, model: "sonnet", sendKey: sendKey)
    #expect(try jsonBody(pinned)["model"] as? String == "sonnet")

    let limit = Endpoint<AwakeLimitUpdated>.setAwakeLimit("s7ackr00", limit: 3)
    #expect(limit.method == .patch && limit.path == "threads/s7ackr00/stack/limit")
    #expect(try jsonBody(limit) == ["limit": 3])

    let bodiless: [(Endpoint<StatusResponse>, String)] = [
        (.stopStack("s7ackr00"), "threads/s7ackr00/stack/stop"),
        (.takeOver("q7adapt0"), "threads/q7adapt0/stack/take-over"),
        (.splitSubThread("q7adapt0"), "threads/q7adapt0/stack/split"),
        (.dropSubThread("q7adapt0"), "threads/q7adapt0/stack/drop"),
        (.retryRepositorySetup("s7ackr00", repository: LenientID("22")), "threads/s7ackr00/repositories/22/retry"),
    ]
    for (endpoint, path) in bodiless {
        #expect(endpoint.method == .post && endpoint.path == path)
        #expect(endpoint.body == nil && endpoint.multipart == nil)
    }

    #expect(Endpoint<ThreadStack>.stack("s7ackr00").path == "threads/s7ackr00/stack")
    #expect(Endpoint<ThreadEnvironment>.environment("s7ackr00").path == "threads/s7ackr00/environment")
    #expect(Endpoint<ThreadShare>.share("s7ackr00").method == .get)

    let everyone = Endpoint<ThreadShareUpdated>.updateShare("s7ackr00", mode: .everyone, userIDs: [7])
    #expect(everyone.method == .put && everyone.path == "threads/s7ackr00/share")
    #expect(try jsonBody(everyone) == ["mode": "everyone", "user_ids": [Int]()])
    let people = Endpoint<ThreadShareUpdated>.updateShare("s7ackr00", mode: .people, userIDs: [7, 8])
    #expect(try jsonBody(people) == ["mode": "people", "user_ids": [7, 8]])
}

@Test func anIdentifierNeverAddsASegment() {
    #expect(Endpoint<ThreadStack>.stack("a/b").path == "threads/a%2Fb/stack")
    #expect(
        Endpoint<StatusResponse>.retryRepositorySetup("a", repository: LenientID("../x")).path
            == "threads/a/repositories/..%2Fx/retry")
}

@Test func decodesEachAnswer() async throws {
    let transport = ScriptedTransport()
    await transport.on("GET threads/s7ackr00/stack", try .scenario(200, "scenario-overview-busy-stack"))
    await transport.on("GET threads/s7ackr00/environment", try .scenario(200, "scenario-overview-environment"))
    await transport.on("GET threads/s7ackr00/share", try .scenario(200, "scenario-overview-share"))
    await transport.on(
        "POST threads/s7ackr00/stack/children", try .scenario(201, "scenario-overview-sub-thread-created"))
    let client = makeClient(transport)
    #expect(try await client.send(.stack("s7ackr00")).children.count == 3)
    #expect(try await client.send(.environment("s7ackr00")).repositories.count == 2)
    #expect(try await client.send(.share("s7ackr00")).people.count == 3)
    let created = try await client.send(
        .addSubThread("s7ackr00", name: "x", brief: "y", kind: .general, model: nil, sendKey: sendKey))
    #expect(created.id == "n3wch11d")
}

@Test func aRefusalCarriesItsCodeAndDetails() async throws {
    let transport = ScriptedTransport()
    await transport.on(
        "POST threads/s7ackr00/stack/children",
        .json(409, #"{"error":"stack_full","message":"m","details":{"count":12,"max":12}}"#))
    do {
        _ = try await makeClient(transport).send(
            .addSubThread("s7ackr00", name: "x", brief: "y", kind: .general, model: nil, sendKey: sendKey))
        Issue.record("expected a refusal")
    } catch let APIFailure.server(error) {
        #expect(error.status == 409 && error.code == "stack_full")
        #expect(error.details["count"] == .number(12) && error.details["max"] == .number(12))
    }
}
