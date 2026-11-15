import Foundation
import Testing

@testable import YakKit

#if canImport(FoundationNetworking)
    import FoundationNetworking
#endif

private let instanceBody = #"{"yak":true,"name":"X","api":{"current":1,"min":1},"pairing":{"qr":true,"address":true}}"#

private func makeClient(_ transport: FakeTransport, recorder: SleepRecorder = SleepRecorder()) -> APIClient {
    APIClient(
        baseURL: URL(string: "https://yak.example.com")!, token: "yakdev_abc", transport: transport,
        appVersion: "1.0.0", systemDescription: "27.2.1; iPhone19,4", sleep: { await recorder.record($0) })
}

private func wakeEndpoint(headers: [String: String] = [:]) -> Endpoint<InstanceInfo> {
    Endpoint(
        method: .get, path: "wake", headers: headers, timeout: .seconds(60), retriesTransientFailures: false)
}

@Suite struct EndpointOptionsTests {
    @Test func anEndpointWithoutOptionsKeepsFifteenSeconds() async throws {
        let transport = FakeTransport(.json(200, instanceBody))
        _ = try await makeClient(transport).send(.instance())
        let request = await transport.requests[0]
        #expect(request.timeoutInterval == 15)
        #expect(request.value(forHTTPHeaderField: "Origin") == nil)
    }

    @Test func headersReachTheRequest() async throws {
        let transport = FakeTransport(.json(200, instanceBody))
        _ = try await makeClient(transport).send(wakeEndpoint(headers: ["Origin": "https://yak.example.com"]))
        let request = await transport.requests[0]
        #expect(request.value(forHTTPHeaderField: "Origin") == "https://yak.example.com")
    }

    @Test func reservedHeadersStayTheClients() async throws {
        let transport = FakeTransport(.json(200, instanceBody))
        let hostile = [
            "authorization": "Bearer other", "User-Agent": "Other", "X-Yak-Protocol": "99", "ACCEPT": "text/plain",
            "content-type": "text/plain",
        ]
        _ = try await makeClient(transport).send(wakeEndpoint(headers: hostile))
        let request = await transport.requests[0]
        #expect(request.value(forHTTPHeaderField: "Authorization") == "Bearer yakdev_abc")
        #expect(request.value(forHTTPHeaderField: "User-Agent") == "YakApp/1.0.0 (27.2.1; iPhone19,4)")
        #expect(request.value(forHTTPHeaderField: "X-Yak-Protocol") == "1")
        #expect(request.value(forHTTPHeaderField: "Accept") == "application/json")
        #expect(request.value(forHTTPHeaderField: "Content-Type") == nil)
    }

    @Test func timeoutComesFromTheEndpoint() async throws {
        let transport = FakeTransport(.json(200, instanceBody))
        _ = try await makeClient(transport).send(wakeEndpoint())
        #expect(await transport.requests[0].timeoutInterval == 60)
    }

    @Test func aTimeoutIsKeptBetweenOneAndSixtySeconds() async throws {
        let transport = FakeTransport(.json(200, instanceBody), .json(200, instanceBody))
        let client = makeClient(transport)
        _ = try await client.send(Endpoint<InstanceInfo>(method: .get, path: "a", timeout: .zero))
        _ = try await client.send(Endpoint<InstanceInfo>(method: .get, path: "b", timeout: .seconds(300)))
        let requests = await transport.requests
        #expect(requests[0].timeoutInterval == 1)
        #expect(requests[1].timeoutInterval == 60)
    }

    @Test func aWakeCapableGetIsSentOnce() async {
        let transport = FakeTransport(.json(503, #"{"error":"unavailable"}"#), .json(200, instanceBody))
        let recorder = SleepRecorder()
        do {
            _ = try await makeClient(transport, recorder: recorder).send(wakeEndpoint())
            Issue.record("expected a failure")
        } catch {
            guard case .server(let apiError) = error else {
                Issue.record("wrong failure \(error)")
                return
            }
            #expect(apiError.code == "unavailable")
        }
        #expect(await transport.requests.count == 1)
        #expect(await recorder.delays.isEmpty)
    }

    @Test func aTimedOutWakeIsNotRetried() async {
        let transport = FakeTransport(.failure(.timedOut), .json(200, instanceBody))
        let recorder = SleepRecorder()
        do {
            _ = try await makeClient(transport, recorder: recorder).send(wakeEndpoint())
            Issue.record("expected a failure")
        } catch {
            #expect(error == .network(.timedOut))
        }
        #expect(await transport.requests.count == 1)
        #expect(await recorder.delays.isEmpty)
    }

    @Test func ordinaryGetsStillRetry() async throws {
        let transport = FakeTransport(.json(503, ""), .json(200, instanceBody))
        let info = try await makeClient(transport).send(.instance())
        #expect(info.name == "X")
        #expect(await transport.requests.count == 2)
    }
}
