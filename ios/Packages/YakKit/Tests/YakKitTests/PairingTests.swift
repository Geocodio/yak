import Foundation
import Testing

@testable import YakKit

#if canImport(FoundationNetworking)
    import FoundationNetworking
#endif

/// Answers by request path; each path replays its queue and repeats the last entry.
private actor RoutedTransport: HTTPTransport {
    private var routes: [String: [FakeTransport.Result]]
    private(set) var paths: [String] = []

    init(_ routes: [String: [FakeTransport.Result]]) { self.routes = routes }

    func send(_ request: URLRequest) async throws -> (Data, HTTPURLResponse) {
        let path = request.url!.path.replacingOccurrences(of: "/api/app/v1/", with: "")
        paths.append(path)
        guard var queue = routes[path], !queue.isEmpty else {
            return (Data(), HTTPURLResponse(url: request.url!, statusCode: 404, httpVersion: nil, headerFields: nil)!)
        }
        let next = queue.count > 1 ? queue.removeFirst() : queue[0]
        routes[path] = queue
        switch next {
        case .failure(let code): throw URLError(code)
        case .response(let status, let body, let headers):
            return (
                Data(body.utf8),
                HTTPURLResponse(url: request.url!, statusCode: status, httpVersion: nil, headerFields: headers)!
            )
        }
    }
}

private let address = URL(string: "https://yak.example.com")!
private let device = DeviceDescription(name: "Phone", model: "iPhone", osVersion: "27.0", appVersion: "1.0")
private let instanceOK = #"{"yak":true,"name":"Yak","api":{"current":1,"min":1},"pairing":{"qr":true,"address":true}}"#
private let pairOK =
    #"{"token":"yakdev_secret","device":{"id":"d1","name":"Phone","kind":"phone"},"user":{"id":1,"name":"Sam","email":"s@example.com"}}"#
private let meOK = """
    {"user":{"id":1,"name":"Sam Lee","email":"s@example.com","initials":"SL","isAdmin":false},
     "device":{"id":"d1","name":"Phone","kind":"phone"},
     "instance":{"name":"Acme Yak","url":"https://yak.example.com","version":"1"},
     "needsYouCount":2,"realtime":null,"terminal":{"wsUrl":"wss://yak.example.com/ws"},
     "attachments":{"maxFiles":4,"maxFileBytes":100},"push":{"apns":true,"bundleId":null,"environment":null},
     "features":{}}
    """
private func refusal(_ code: String, status: Int = 400) -> FakeTransport.Result {
    .json(status, #"{"error":"\#(code)","message":"m","details":{}}"#)
}

private struct Harness {
    let transport: RoutedTransport
    let store: InstanceStore
    let sleeps: SleepRecorder
    let pairing: Pairing

    init(_ routes: [String: [FakeTransport.Result]]) {
        transport = RoutedTransport(routes)
        store = InstanceStore(defaults: MemoryDefaults(), tokens: InMemoryTokenStore(), now: { Date() })
        let sleeps = SleepRecorder()
        self.sleeps = sleeps
        let transport = transport
        pairing = Pairing(
            makeClient: {
                APIClient(baseURL: $0, token: nil, transport: transport, appVersion: "1.0", systemDescription: "test")
            },
            instances: store, sleep: { await sleeps.record($0) })
    }
}

private final class MemoryDefaults: KeyValueStore, @unchecked Sendable {
    private let lock = NSLock()
    private var values: [String: Data] = [:]
    func data(forKey key: String) -> Data? { lock.withLock { values[key] } }
    func set(_ value: Data?, forKey key: String) { lock.withLock { values[key] = value } }
}

@Suite struct PairingTests {
    private let link = PairingLink(url: URL(string: "yak://pair?url=https%3A%2F%2Fyak.example.com&code=abc")!)!

    @Test func pairsWithACodeAndStoresTheInstance() async throws {
        let harness = Harness([
            "instance": [.json(200, instanceOK)], "device/pair": [.json(200, pairOK)], "me": [.json(200, meOK)],
        ])
        let instance = try await harness.pairing.pair(link: link, device: device)
        let stored = await harness.store.all()
        #expect(stored.count == 1)
        #expect(stored.first?.url == address)
        #expect(instance.name == "Acme Yak")
        #expect(instance.userName == "Sam Lee")
        #expect(await harness.store.state(of: instance.id) == .ready)
    }

    @Test func aWebSiteThatIsNotYakIsReported() async {
        let harness = Harness(["instance": [.json(200, "<html>Sign in to the Wi-Fi</html>")]])
        await #expect(throws: PairingProblem.notYak) { try await harness.pairing.pair(link: link, device: device) }
        #expect(await !harness.transport.paths.contains("device/pair"))
        #expect(await harness.store.all().isEmpty)
    }

    @Test func mapsEachPairingRefusal() async {
        let cases: [(String, [String: [FakeTransport.Result]], PairingProblem)] = [
            ("invalid", ["device/pair": [refusal("pairing_code_invalid")]], .codeInvalid),
            ("expired", ["device/pair": [refusal("pairing_code_expired")]], .codeExpired),
            (
                "app too old",
                [
                    "device/pair": [
                        .json(426, #"{"error":"protocol_too_old","message":"","details":{"min_protocol":2}}"#)
                    ]
                ],
                .appTooOld
            ),
            (
                "server too old", ["device/pair": [.json(200, pairOK, headers: ["X-Yak-Protocol": "0"])]], .serverTooOld
            ),
            ("network", ["device/pair": [.failure(.notConnectedToInternet)]], .unreachable),
        ]
        for (name, routes, expected) in cases {
            let harness = Harness(["instance": [.json(200, instanceOK)]].merging(routes) { $1 })
            await #expect(throws: expected, "\(name)") { try await harness.pairing.pair(link: link, device: device) }
            #expect(await harness.store.all().isEmpty, "\(name)")
        }
    }

    @Test func checkRefusesAnInstanceOutsideTheProtocolRange() async {
        let newer = #"{"yak":true,"name":"Y","api":{"current":5,"min":3},"pairing":{"qr":true,"address":true}}"#
        let older = #"{"yak":true,"name":"Y","api":{"current":0,"min":0},"pairing":{"qr":true,"address":true}}"#
        let notYak = #"{"yak":false,"name":"Y","api":{"current":1,"min":1},"pairing":{"qr":true,"address":true}}"#
        for (body, expected) in [(newer, PairingProblem.appTooOld), (older, .serverTooOld), (notYak, .notYak)] {
            let harness = Harness(["instance": [.json(200, body)]])
            await #expect(throws: expected) { try await harness.pairing.check(address) }
        }
    }

    @Test func deviceFlowPollsAtTheServersInterval() async throws {
        let harness = Harness([
            "instance": [.json(200, instanceOK)],
            "device/code": [
                .json(
                    200,
                    #"{"device_code":"dc","user_code":"UC","verification_url":"https://yak.example.com/d","verification_url_complete":"https://yak.example.com/d?c=UC","expires_in":600,"interval":5}"#
                )
            ],
            "device/token": [refusal("authorization_pending"), refusal("authorization_pending"), .json(200, pairOK)],
            "me": [.json(200, meOK)],
        ])
        let flow = try await harness.pairing.startDeviceFlow(address: address, device: device)
        let instance = try await harness.pairing.waitForApproval(flow)
        #expect(instance.name == "Acme Yak")
        #expect(await harness.sleeps.delays == [.seconds(5), .seconds(5), .seconds(5)])
    }

    private func flow(interval: Int = 5, expiresIn: Int = 600) -> PendingDeviceFlow {
        let info = try! JSONDecoder().decode(InstanceInfo.self, from: Data(instanceOK.utf8))
        let code = try! JSONDecoder().decode(
            DeviceCode.self,
            from: Data(
                #"{"device_code":"dc","user_code":"UC","verification_url":"u","verification_url_complete":"u","expires_in":\#(expiresIn),"interval":\#(interval)}"#
                    .utf8))
        return PendingDeviceFlow(instance: info, url: address, code: code)
    }

    @Test func slowDownLengthensTheInterval() async throws {
        let harness = Harness([
            "device/token": [refusal("slow_down"), refusal("authorization_pending"), .json(200, pairOK)],
            "me": [.json(200, meOK)],
        ])
        _ = try await harness.pairing.waitForApproval(flow())
        #expect(await harness.sleeps.delays == [.seconds(5), .seconds(10), .seconds(10)])
    }

    @Test func cancellingTheWaitThrowsCancelled() async {
        let transport = RoutedTransport(["device/token": [refusal("authorization_pending")]])
        let store = InstanceStore(defaults: MemoryDefaults(), tokens: InMemoryTokenStore(), now: { Date() })
        let pairing = Pairing(
            makeClient: {
                APIClient(baseURL: $0, token: nil, transport: transport, appVersion: "1.0", systemDescription: "t")
            },
            instances: store, sleep: { try await Task.sleep(for: $0 * 1000) })
        let pending = flow()
        let task = Task { () async -> PairingProblem? in
            do throws(PairingProblem) { _ = try await pairing.waitForApproval(pending) } catch { return error }
            return nil
        }
        try? await Task.sleep(for: .milliseconds(50))
        task.cancel()
        #expect(await task.value == .cancelled)
        #expect(await store.all().isEmpty)
    }

    @Test func anExpiredDeviceCodeIsReported() async {
        let harness = Harness(["device/token": [refusal("expired_token")]])
        await #expect(throws: PairingProblem.codeExpired) { try await harness.pairing.waitForApproval(flow()) }
    }

    @Test func waitingStopsWhenTheCodeLifetimeHasPassed() async {
        let harness = Harness(["device/token": [refusal("authorization_pending")]])
        await #expect(throws: PairingProblem.codeExpired) {
            try await harness.pairing.waitForApproval(flow(interval: 5, expiresIn: 12))
        }
        #expect(await harness.sleeps.delays.count == 3)
    }

    @Test func deniedIsReported() async {
        let harness = Harness(["device/token": [refusal("access_denied")]])
        await #expect(throws: PairingProblem.denied) { try await harness.pairing.waitForApproval(flow()) }
    }
}
