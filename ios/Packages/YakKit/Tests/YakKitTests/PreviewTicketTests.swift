import Foundation
import Testing

@testable import YakKit

#if canImport(FoundationNetworking)
    import FoundationNetworking
#endif

private let threadID = "d0e515ef"
private let statusRoute = "GET threads/\(threadID)/preview/status"
private let ticketRoute = "POST threads/\(threadID)/preview/ticket"
private let previewHost = "https://t-p7m4q2hx9z.yak.example.test"

private struct Rig {
    let session: PreviewSession
    let transport: ScriptedTransport
    let clock: ManualClock
    let recorder: StreamRecorder<PreviewSnapshot>

    init(status: String = "scenario-preview-ready", opensPane: Bool = true) async throws {
        clock = ManualClock()
        transport = ScriptedTransport()
        await transport.on(statusRoute, try .scenario(200, status))
        await transport.on("POST threads/\(threadID)/preview/warm", .json(204, ""))
        await transport.on("POST threads/\(threadID)/preview/heartbeat", .json(204, ""))
        await transport.on(ticketRoute, try .scenario(200, "scenario-preview-ticket"))
        let client = APIClient(
            baseURL: URL(string: "https://example.test")!, token: "test-token", transport: transport,
            appVersion: "1.0", systemDescription: "Test", sleep: { _ in })
        session = PreviewSession(threadID: threadID, client: client, clock: clock, allowsLoopbackHTTP: false)
        recorder = StreamRecorder(await session.snapshots())
        if opensPane {
            await session.setVisible(true)
            try await loaded()
        }
    }

    @discardableResult
    func loaded() async throws -> PreviewSnapshot {
        try await recorder.next { $0.load == .ready }
    }

    func ticketRequests() async -> [URLRequest] { await transport.requests(on: ticketRoute) }
}

@Test func signingInIsBounded() async throws {
    let rig = try await Rig()
    let expected = URL(string: "\(previewHost)/_yak/auth?ticket=scenario-ticket&to=%2Fpricing")!
    #expect(await rig.session.ticket(path: "/pricing", reason: .firstLoad) == .url(expected))
    #expect(await rig.session.ticket(path: "/pricing", reason: .signInAgain) != .tooManyAttempts)
    #expect(await rig.session.ticket(path: "/pricing", reason: .signInAgain) == .tooManyAttempts)
    #expect(await rig.ticketRequests().count == 2)
    #expect(await rig.session.ticket(path: "/", reason: .openInBrowser) != .tooManyAttempts)
    await rig.clock.advance(by: .seconds(61))
    #expect(await rig.session.ticket(path: "/", reason: .signInAgain) != .tooManyAttempts)
}

@Test func tryAgainClearsTheBound() async throws {
    let rig = try await Rig()
    _ = await rig.session.ticket(path: "/", reason: .firstLoad)
    _ = await rig.session.ticket(path: "/", reason: .signInAgain)
    #expect(await rig.session.ticket(path: "/", reason: .signInAgain) == .tooManyAttempts)
    #expect(await rig.session.ticket(path: "/", reason: .personRetry) != .tooManyAttempts)
    #expect(await rig.session.ticket(path: "/", reason: .signInAgain) != .tooManyAttempts)
    #expect(await rig.session.ticket(path: "/", reason: .signInAgain) == .tooManyAttempts)
}

@Test func theRequestCarriesTheNormalizedPath() async throws {
    let rig = try await Rig()
    _ = await rig.session.ticket(path: "//other.example.com/x", reason: .firstLoad)
    let body = try #require(await rig.ticketRequests().first?.httpBody)
    #expect(String(decoding: body, as: UTF8.self) == #"{"path":"\/other.example.com\/x"}"#)
}

@Test func aTicketForAnotherHostIsRefused() async throws {
    let rig = try await Rig()
    let answer = try Scenarios.data(
        "scenario-preview-ticket", setting: ["url"], toJSON: #""https://evil.example.com/_yak/auth?ticket=x&to=%2F""#)
    await rig.transport.on(ticketRoute, .json(200, String(decoding: answer, as: UTF8.self)))
    #expect(await rig.session.ticket(path: "/", reason: .firstLoad) == .unexpectedHost)
}

@Test func refusalsRefreshTheStatus() async throws {
    let rig = try await Rig()
    await rig.transport.on(
        ticketRoute, .json(409, #"{"error":"thread_closed","message":"This thread is closed."}"#))
    #expect(
        await rig.session.ticket(path: "/", reason: .openInBrowser)
            == .refused(code: "thread_closed", message: "This thread is closed."))
    await rig.transport.waitForRequests(2, on: statusRoute)
    await rig.transport.on(
        ticketRoute, .json(422, #"{"error":"validation_failed","message":"Invalid.","details":{"errors":{}}}"#))
    #expect(
        await rig.session.ticket(path: "/", reason: .openInBrowser)
            == .refused(code: "validation_failed", message: "Invalid."))
    await rig.transport.waitForRequests(3, on: statusRoute)
    await rig.transport.on(ticketRoute, .json(500, #"{"error":"server_error","message":"Failed."}"#))
    #expect(
        await rig.session.ticket(path: "/", reason: .openInBrowser)
            == .failed(.server(code: "server_error", message: "Failed.")))
}

@Test func previewsSwitchedOffIsNotOffline() async throws {
    let rig = try await Rig()
    await rig.transport.on(
        ticketRoute,
        .json(503, #"{"error":"unavailable","message":"Previews are switched off on this Yak."}"#))
    #expect(
        await rig.session.ticket(path: "/", reason: .firstLoad)
            == .refused(code: "unavailable", message: "Previews are switched off on this Yak."))
    #expect(await rig.transport.requests(on: statusRoute).count == 1)
    await rig.transport.on(ticketRoute, .json(503, #"{"error":"maintenance","message":"Down."}"#))
    #expect(await rig.session.ticket(path: "/", reason: .openInBrowser) == .failed(.offline))
}

@Test func anUnknownStateMintsWithTheLastPreviewURL() async throws {
    let rig = try await Rig()
    let unknown = try Scenarios.data("scenario-preview-ready", setting: ["state"], toJSON: #""hibernating""#)
    let withoutURL = try JSONSerialization.data(
        withJSONObject: {
            var object = try JSONSerialization.jsonObject(with: unknown) as? [String: Any] ?? [:]
            object["url"] = NSNull()
            return object
        }())
    await rig.transport.on(statusRoute, .json(200, String(decoding: withoutURL, as: UTF8.self)))
    await rig.session.refresh()
    _ = try await rig.recorder.next { $0.status?.url == nil }
    guard case .url(let url) = await rig.session.ticket(path: "/", reason: .openInBrowser) else {
        Issue.record("expected a ticket URL")
        return
    }
    #expect(url.host == "t-p7m4q2hx9z.yak.example.test")
}

@Test func noTicketBeforeAStatus() async throws {
    let none = try await Rig(opensPane: false)
    #expect(
        await none.session.ticket(path: "/", reason: .firstLoad) == .failed(.server(code: "not_ready", message: "")))
    let starting = try await Rig(status: "scenario-preview-starting")
    #expect(
        await starting.session.ticket(path: "/", reason: .firstLoad) == .failed(.server(code: "not_ready", message: ""))
    )
    let sent = await none.ticketRequests().count + starting.ticketRequests().count
    #expect(sent == 0)
}
