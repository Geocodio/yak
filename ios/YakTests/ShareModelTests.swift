import Foundation
import Testing
import YakKit

@testable import Yak

private var scenarios: URL {
    var directory = URL(fileURLWithPath: #filePath)
    while directory.lastPathComponent != "ios" { directory.deleteLastPathComponent() }
    return directory.appendingPathComponent("Packages/YakKit/Tests/YakKitTests/Scenarios")
}

private final class ShareTransport: HTTPTransport, @unchecked Sendable {
    private let lock = NSLock()
    private var saves = 0
    var refusesSave = false

    var saveCount: Int { lock.withLock { saves } }

    func send(_ request: URLRequest) async throws -> (Data, HTTPURLResponse) {
        var status = 200
        let body: String
        if request.httpMethod == "PUT" {
            lock.withLock { saves += 1 }
            try await Task.sleep(for: .milliseconds(50))
            if refusesSave {
                status = 409
                body = #"{"error":"conflict","message":"The sharing could not change right now."}"#
            } else {
                body = #"{"mode":"everyone","userIds":[]}"#
            }
        } else {
            body =
                (try? String(
                    contentsOf: scenarios.appendingPathComponent("scenario-overview-share.json"), encoding: .utf8))
                ?? "{}"
        }
        let response = HTTPURLResponse(
            url: request.url!, statusCode: status, httpVersion: nil,
            headerFields: ["Content-Type": "application/json", "X-Yak-Protocol": "1"])!
        return (Data(body.utf8), response)
    }
}

private struct StillClock: YakClock {
    func now() -> Date { Date(timeIntervalSince1970: 1_794_000_000) }
    func sleep(for duration: Duration) async throws { try await Task.sleep(for: .seconds(3600)) }
}

private final class MemoryDefaults: KeyValueStore, @unchecked Sendable {
    private let lock = NSLock()
    private var values: [String: Data] = [:]
    func data(forKey key: String) -> Data? { lock.withLock { values[key] } }
    func set(_ value: Data?, forKey key: String) { lock.withLock { values[key] = value } }
}

@MainActor private func makeModel(_ transport: ShareTransport) -> ShareModel {
    let instance = Instance(
        url: URL(string: "https://example.test")!, name: "Example Yak", userName: "Test Person", lastUsedAt: Date())
    let client = APIClient(
        baseURL: instance.url, token: "token", transport: transport, appVersion: "1.0", systemDescription: "Test",
        sleep: { _ in })
    let clock = StillClock()
    let actions = OverviewActions(
        client: client, cache: Cache(instance: instance.id, files: InMemoryFileStore(), clock: clock), clock: clock,
        makeID: { UUID() })
    return ShareModel(threadID: "s7ackr00", actions: actions, clock: clock)
}

@MainActor @Test func startsFromTheServersChoice() async {
    let model = makeModel(ShareTransport())
    await model.start()
    #expect(model.load == .ready)
    #expect(model.form?.mode == .people)
    #expect(model.form?.selected == [LenientID("7")])
    #expect(model.secretFiles == [".env"])
}

@MainActor @Test func aFailedSaveKeepsTheChoice() async {
    let transport = ShareTransport()
    transport.refusesSave = true
    let model = makeModel(transport)
    await model.start()
    model.setMode(.everyone)
    #expect(await model.save() == false)
    #expect(model.problem == "The sharing could not change right now.")
    #expect(model.form?.mode == .everyone)
    #expect(!model.isSaving)
}

@MainActor @Test func aDoubleTapSavesOnce() async {
    let transport = ShareTransport()
    let model = makeModel(transport)
    await model.start()
    model.setMode(.everyone)
    async let first = model.save()
    async let second = model.save()
    let results = await [first, second]
    #expect(results.filter { $0 }.count == 1)
    #expect(transport.saveCount == 1)
}

@MainActor @Test func onlyTheOwnerIsFixed() async {
    let model = makeModel(ShareTransport())
    await model.start()
    let before = model.form?.selected
    model.toggle(LenientID("6"))
    #expect(model.form?.selected == before)
    model.toggle(LenientID("8"))
    #expect(model.form?.selected.contains(LenientID("8")) == true)
}
