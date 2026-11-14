import Foundation
import Testing

@testable import YakKit

#if canImport(FoundationNetworking)
    import FoundationNetworking
#endif

private let baseURL = URL(string: "https://yak.example.com")!
private let instanceBody = #"{"yak":true,"name":"X","api":{"current":1,"min":1},"pairing":{"qr":true,"address":true}}"#

private func makeClient(_ transport: FakeTransport, recorder: SleepRecorder = SleepRecorder()) -> APIClient {
    APIClient(
        baseURL: baseURL, token: "yakdev_abc", transport: transport, appVersion: "1.0.0",
        systemDescription: "27.2.1; iPhone19,4", sleep: { await recorder.record($0) })
}

private func failure(of transport: FakeTransport, recorder: SleepRecorder = SleepRecorder()) async -> APIFailure? {
    do {
        _ = try await makeClient(transport, recorder: recorder).send(.instance())
        return nil
    } catch {
        return error
    }
}

@Test func sendsTheStandardHeaders() async throws {
    let transport = FakeTransport(.json(200, instanceBody))
    _ = try await makeClient(transport).send(.instance())
    let request = await transport.requests[0]
    #expect(request.url?.absoluteString == "https://yak.example.com/api/app/v1/instance")
    #expect(request.value(forHTTPHeaderField: "X-Yak-Protocol") == "1")
    #expect(request.value(forHTTPHeaderField: "Authorization") == "Bearer yakdev_abc")
    #expect(request.value(forHTTPHeaderField: "User-Agent") == "YakApp/1.0.0 (27.2.1; iPhone19,4)")
    #expect(request.value(forHTTPHeaderField: "Accept") == "application/json")
    #expect(request.timeoutInterval == 15)
}

@Test func decodesASuccessfulBody() async throws {
    let transport = FakeTransport(.json(200, instanceBody))
    let info = try await makeClient(transport).send(.instance())
    #expect(info.name == "X")
    #expect(info.api == .init(current: 1, min: 1))
}

@Test func mapsFailures() async {
    #expect(
        await failure(of: FakeTransport(.json(426, #"{"error":"protocol_too_old","details":{"min_protocol":3}}"#)))
            == .appTooOld(minimum: 3))
    #expect(
        await failure(of: FakeTransport(.json(200, instanceBody, headers: ["X-Yak-Protocol": "0"])))
            == .serverTooOld(current: 0))
    #expect(
        await failure(of: FakeTransport(.json(401, #"{"error":"device_revoked","message":"gone"}"#)))
            == .unauthorized(code: "device_revoked"))
    #expect(
        await failure(of: FakeTransport(.json(409, #"{"error":"thread_closed","message":"m","details":{"a":1}}"#)))
            == .server(APIError(status: 409, code: "thread_closed", message: "m", details: ["a": .number(1)])))
    #expect(await failure(of: FakeTransport(.json(200, "<html>captive portal</html>"))) == .notYak)
    #expect(await failure(of: FakeTransport(.json(404, "<html>not here</html>"))) == .notYak)
    #expect(
        await failure(of: FakeTransport(.json(500, "oops")))
            == .server(APIError(status: 500, code: "unknown", message: "", details: [:])))
}

@Test func retriesAGetOnNetworkFailureWithBackoff() async {
    let transport = FakeTransport(.failure(.notConnectedToInternet))
    let recorder = SleepRecorder()
    #expect(await failure(of: transport, recorder: recorder) == .network(.notConnectedToInternet))
    #expect(await transport.requests.count == 4)
    #expect(await recorder.delays == [.milliseconds(500), .seconds(1), .seconds(2)])
}

@Test func retriesAGetOnGatewayErrorsThenSucceeds() async throws {
    let transport = FakeTransport(.json(503, ""), .json(502, ""), .json(200, instanceBody))
    let info = try await makeClient(transport).send(.instance())
    #expect(info.yak)
    #expect(await transport.requests.count == 3)
}

@Test func doesNotRetryAPost() async {
    let transport = FakeTransport(.failure(.timedOut))
    let client = makeClient(transport)
    let endpoint = Endpoint<InstanceInfo>(method: .post, path: "device/pair", body: Data("{}".utf8))
    do {
        _ = try await client.send(endpoint)
        Issue.record("expected a failure")
    } catch {
        #expect(error == .network(.timedOut))
    }
    #expect(await transport.requests.count == 1)
    #expect(await transport.requests[0].value(forHTTPHeaderField: "Content-Type") == "application/json")
}

@Test func neverPutsTheTokenInADescription() async {
    let transport = FakeTransport(.json(401, #"{"error":"invalid_token","message":"no"}"#))
    let client = makeClient(transport)
    let failure = await failure(of: transport)
    #expect(!String(describing: client).contains("yakdev_"))
    #expect(!String(describing: failure as Any).contains("yakdev_"))
}
