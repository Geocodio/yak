import Foundation
import Testing

@testable import YakKit

#if canImport(FoundationNetworking)
    import FoundationNetworking
#endif

private let baseURL = URL(string: "https://yak.example.com")!

private func makeClient(_ transport: any HTTPTransport) -> APIClient {
    APIClient(
        baseURL: baseURL, token: "yakdev_abc", transport: transport, appVersion: "1.0.0",
        systemDescription: "27.2; iPhone19,4", sleep: { _ in })
}

@Test(arguments: [
    "scenario-preview-ready", "scenario-preview-starting", "scenario-preview-waking",
    "scenario-preview-waiting-for-slot", "scenario-preview-failing", "scenario-preview-fixing",
    "scenario-preview-no-manifest", "scenario-preview-invalid-manifest", "scenario-preview-destroyed",
    "scenario-preview-closed", "scenario-preview-local-machine",
])
func everyPreviewScenarioDecodes(name: String) throws {
    #expect(try decoded(PreviewStatus.self, name).state.rawValue.isEmpty == false)
}

@Test func theReadyScenarioHasItsURLAndManifest() throws {
    let status = try decoded(PreviewStatus.self, "scenario-preview-ready")
    #expect(status.state == .ready && status.url == "https://t-p7m4q2hx9z.yak.example.test/" && status.port == 5173)
    #expect(status.coldStartCommand == "npm run dev")
    #expect(status.setup == PreviewSetup(intent: nil, isRunning: false, endedWithoutManifest: false))
    #expect(!status.state.isTransitional)
}

@Test func aFixInProgressIsDecoded() throws {
    let status = try decoded(PreviewStatus.self, "scenario-preview-fixing")
    #expect(status.state == .failing)
    #expect(status.setup.intent == .fixPreview && status.setup.isRunning)
    #expect(status.error == "The app did not answer on port 5173.")
    #expect(status.log.count == 2)
}

@Test func theStartingScenarioIsTransitionalWithoutAURL() throws {
    let status = try decoded(PreviewStatus.self, "scenario-preview-starting")
    #expect(status.state.isTransitional && status.url == nil && status.steps.count == 2)
}

@Test func aMissingManifestHasNoPortOrCommand() throws {
    let status = try decoded(PreviewStatus.self, "scenario-preview-no-manifest")
    #expect(status.port == nil && status.coldStartCommand == nil)
}

@Test func anUnknownStateIsKept() throws {
    let data = try Scenarios.data("scenario-preview-ready", setting: ["state"], toJSON: "\"hibernating\"")
    let status = try JSONDecoder().decode(PreviewStatus.self, from: data)
    #expect(status.state.rawValue == "hibernating" && !status.state.isTransitional)
}

@Test func aMissingSetupDecodesAsEmpty() throws {
    var object = try #require(
        try JSONSerialization.jsonObject(with: Scenarios.data("scenario-preview-ready")) as? [String: Any])
    object["setup"] = nil
    let status = try JSONDecoder().decode(PreviewStatus.self, from: JSONSerialization.data(withJSONObject: object))
    #expect(status.setup == PreviewSetup(intent: nil, isRunning: false, endedWithoutManifest: false))
}

@Test func aMissingRequiredFieldFails() throws {
    var object = try #require(
        try JSONSerialization.jsonObject(with: Scenarios.data("scenario-preview-ready")) as? [String: Any])
    object["steps"] = nil
    #expect(throws: DecodingError.self) {
        try JSONDecoder().decode(PreviewStatus.self, from: JSONSerialization.data(withJSONObject: object))
    }
}

@Test func replacingTheStateKeepsTheRest() throws {
    let status = try decoded(PreviewStatus.self, "scenario-preview-failing")
    let retrying = status.with(state: .startingDevServer)
    #expect(retrying.state == .startingDevServer && retrying.error == status.error && retrying.log == status.log)
}

@Test(arguments: [
    (PreviewState.wakingSandbox, true), (.startingDevServer, true), (.waitingForSlot, true), (.ready, false),
    (.failing, false), (.destroyed, false), (PreviewState(rawValue: "sandbox_asleep"), false),
])
func transitionalStates(state: PreviewState, expected: Bool) {
    #expect(state.isTransitional == expected)
}

@Test func buildsEveryPreviewRequest() async throws {
    let transport = ScriptedTransport()
    await transport.on("GET threads/d0e515ef/preview/status", try .scenario(200, "scenario-preview-ready"))
    await transport.on("POST threads/d0e515ef/preview/warm", .json(204, ""))
    await transport.on("POST threads/d0e515ef/preview/heartbeat", .json(204, ""))
    await transport.on("POST threads/d0e515ef/preview/ticket", try .scenario(200, "scenario-preview-ticket"))
    await transport.on("POST threads/d0e515ef/preview/restore", try .scenario(202, "scenario-preview-restore"))
    let client = makeClient(transport)

    #expect(try await client.send(.previewStatus("d0e515ef")).state == .ready)
    _ = try await client.send(.warmPreview("d0e515ef"))
    _ = try await client.send(.previewHeartbeat("d0e515ef"))
    let ticket = try await client.send(.previewTicket("d0e515ef", path: "/pricing"))
    #expect(ticket.url.hasSuffix("&to=%2Fpricing") && ticket.expiresAt == "2026-11-15T12:01:00+00:00")
    #expect(try await client.send(.restorePreview("d0e515ef")) == StatusResponse(status: "restoring"))

    let requests = await transport.requests
    #expect(requests.map { $0.httpMethod } == ["GET", "POST", "POST", "POST", "POST"])
    let tail = requests.compactMap { $0.url?.path }.map {
        String($0.split(separator: "/").suffix(2).joined(separator: "/"))
    }
    #expect(tail == ["preview/status", "preview/warm", "preview/heartbeat", "preview/ticket", "preview/restore"])
    #expect(requests[1].httpBody == nil && requests[4].httpBody == nil)
    let ticketBody = try #require(requests[3].httpBody)
    let sent = try #require(try JSONSerialization.jsonObject(with: ticketBody) as? [String: String])
    #expect(sent == ["path": "/pricing"])
}

@Test func theTicketBodyCarriesThePathAsGiven() throws {
    let endpoint = Endpoint<PreviewTicket>.previewTicket("d0e515ef", path: "/pricing?x=1")
    let body = try #require(endpoint.body)
    let object = try #require(
        try JSONSerialization.jsonObject(with: JSONEncoder().encode(body)) as? [String: String])
    #expect(object == ["path": "/pricing?x=1"] && endpoint.method == .post)
}

@Test func escapesTheThreadIdentifier() {
    #expect(Endpoint<PreviewStatus>.previewStatus("d0e5/5ef").path == "threads/d0e5%2F5ef/preview/status")
    #expect(Endpoint<StatusResponse>.restorePreview("a b").path == "threads/a%20b/preview/restore")
}

@Test func statusIsNotRetriedByTheClient() {
    #expect(!Endpoint<PreviewStatus>.previewStatus("a").retriesTransientFailures)
}

@Test func refusalsMapToTheirCodes() async throws {
    let transport = ScriptedTransport()
    await transport.on(
        "POST threads/d0e515ef/preview/ticket",
        .json(503, #"{"error":"unavailable","message":"Previews are switched off on this Yak."}"#))
    do {
        _ = try await makeClient(transport).send(.previewTicket("d0e515ef", path: "/"))
        Issue.record("expected a failure")
    } catch {
        guard case .server(let refusal) = error else {
            Issue.record("expected a server refusal, got \(error)")
            return
        }
        #expect(refusal.status == 503 && refusal.code == "unavailable")
        #expect(refusal.message == "Previews are switched off on this Yak.")
    }
}

@Test(arguments: ["scenario-preview-ticket", "scenario-preview-restore"])
func theActionScenariosLoad(name: String) throws {
    #expect(try JSONSerialization.jsonObject(with: Scenarios.data(name)) is [String: Any])
}
