import Foundation
import Testing

@testable import YakKit

#if canImport(FoundationNetworking)
    import FoundationNetworking
#endif

private let storeRoute = "POST threads"
private let optionsRoute = "GET threads/new"

private struct Rig {
    let submission: StartThreadSubmission
    let transport: ScriptedTransport
    let clock: ManualClock
    let files: InMemoryFileStore
    let tokens: InMemoryTokenStore
    let instance: Instance
    let ids: IDSequence

    var inbox: [String] { (try? files.list("instances/\(instance.id.uuidString)/outbox/incoming")) ?? [] }

    func request(
        repo: String = "example/api", mode: String = "fix", text: String = "Retry failed rows"
    ) -> StartThreadRequest {
        StartThreadRequest(instance: instance.id, repo: repo, mode: mode, text: text)
    }

    func submit(_ request: StartThreadRequest, budget: Duration = .seconds(10)) async -> StartThreadOutcome {
        await submission.submit(request, budget: budget)
    }

    /// Runs `work` while stepping the manual clock, so a budget elapses.
    func advancing<Result: Sendable>(_ work: @escaping @Sendable () async -> Result) async -> Result {
        let task = Task { await work() }
        let clock = clock
        let stepper = Task {
            while !Task.isCancelled {
                await clock.advance(by: .seconds(1))
                await Task.yield()
            }
        }
        let result = await task.value
        stepper.cancel()
        return result
    }
}

private func makeRig() async throws -> Rig {
    let transport = ScriptedTransport()
    let clock = ManualClock()
    let files = InMemoryFileStore()
    let tokens = InMemoryTokenStore()
    let store = InstanceStore(
        defaults: InMemoryKeyValueStore(), tokens: tokens, now: { Date(timeIntervalSince1970: 1_000) })
    let instance = try await store.add(
        url: URL(string: "https://yak.example.com")!, name: "Example Yak", userName: "Test person",
        token: "bearer-one")
    let ids = IDSequence()
    let environment = SessionEnvironment(
        http: transport, webSockets: FakeWebSocketServer(), files: files, clock: clock, makeID: ids.next,
        appVersion: "1.0.0", systemDescription: "Test", allowsInsecureRealtime: false)
    return Rig(
        submission: StartThreadSubmission(instances: store, tokens: tokens, environment: environment),
        transport: transport, clock: clock, files: files, tokens: tokens, instance: instance, ids: ids)
}

private func sendKey(_ request: URLRequest) -> String? {
    (request.httpBody.flatMap { try? JSONSerialization.jsonObject(with: $0) } as? [String: Any])?["send_key"]
        as? String
}

private func refusal(_ status: Int, _ message: String) -> FakeTransport.Result {
    let escaped = String(decoding: (try? JSONEncoder().encode(message)) ?? Data(), as: UTF8.self)
    return .json(status, #"{"error":"validation_failed","message":\#(escaped)}"#)
}

/// Answers like Task S3's server: the first request with a key creates the thread, every repeat of the key,
/// at any time, gets the same one.
private final class KeyedServer: @unchecked Sendable {
    private let lock = NSLock()
    private var threads: [String: String] = [:]
    var createdCount: Int { lock.withLock { threads.count } }
    func answer(_ request: URLRequest) -> FakeTransport.Result {
        let key = sendKey(request) ?? "none"
        let id = lock.withLock { () -> String in
            if let known = threads[key] { return known }
            threads[key] = "k7m2q9rt"
            return "k7m2q9rt"
        }
        return .json(201, #"{"id":"\#(id)"}"#)
    }
}

@Suite struct StartThreadSubmissionTests {
    @Test func theItemIsWrittenBeforeTheRequestAndRepeatsWithTheSameKey() async throws {
        for delay in [Duration.zero, .seconds(86_400)] {
            let rig = try await makeRig()
            let seenBefore = Locked<[String]>([])
            let server = KeyedServer()
            await rig.transport.on(prefix: storeRoute) { request in
                seenBefore.mutate {
                    $0 = (try? rig.files.list("instances/\(rig.instance.id.uuidString)/outbox/incoming")) ?? []
                }
                return server.answer(request)
            }
            // The first attempt gets no answer, so the item stays and the app repeats it later.
            await rig.transport.on(storeRoute, .failure(.timedOut))
            let outcome = await rig.submit(rig.request())
            #expect(outcome == .saved)
            let first = try #require(await rig.transport.requests(on: storeRoute).first)
            let key = try #require(sendKey(first))
            #expect(rig.inbox == ["\(key).json"])

            await rig.clock.advance(by: delay)
            // A fresh outbox over the same files imports the item and repeats it.
            let outbox = Outbox(
                instance: rig.instance.id,
                client: APIClient(
                    baseURL: rig.instance.url, token: "bearer-one", transport: rig.transport,
                    appVersion: "1.0.0", systemDescription: "Test"),
                files: rig.files, clock: rig.clock, makeID: rig.ids.next)
            await rig.transport.on(prefix: storeRoute) { server.answer($0) }
            await rig.transport.on(storeRoute, results: [])
            let deliveries = StreamRecorder(await outbox.deliveries())
            await outbox.load()
            let delivery = try await deliveries.next { _ in true }
            #expect(delivery.threadID == "k7m2q9rt")
            let keys = Set(await rig.transport.requests(on: storeRoute).compactMap(sendKey))
            #expect(keys == [key])
            #expect(server.createdCount == 1)
        }
    }

    @Test func theInboxFileExistsWhenTheRequestArrives() async throws {
        let rig = try await makeRig()
        let seen = Locked<[String]>([])
        await rig.transport.on(prefix: storeRoute) { request in
            let names = (try? rig.files.list("instances/\(rig.instance.id.uuidString)/outbox/incoming")) ?? []
            seen.mutate { $0 = names }
            return .json(201, #"{"id":"k7m2q9rt"}"#)
        }
        _ = await rig.submit(rig.request())
        let key = try #require(await rig.transport.requests(on: storeRoute).first.flatMap(sendKey))
        #expect(seen.value == ["\(key).json"])
    }

    @Test func aStartRemovesTheInboxFile() async throws {
        let rig = try await makeRig()
        await rig.transport.on(storeRoute, try .scenario(201, "scenario-thread-store"))
        #expect(await rig.submit(rig.request()) == .started(threadID: "k7m2q9rt"))
        #expect(rig.inbox.isEmpty)
        let body = try #require(await rig.transport.requests(on: storeRoute).first?.httpBody)
        let json = try #require(try JSONSerialization.jsonObject(with: body) as? [String: Any])
        #expect(json["repo"] as? String == "example/api")
        #expect(json["mode"] as? String == "fix")
        #expect(json["description"] as? String == "Retry failed rows")
    }

    @Test func aRefusalRemovesTheItemAndSaysWhy() async throws {
        for status in [403, 404, 409, 422] {
            let rig = try await makeRig()
            await rig.transport.on(storeRoute, refusal(status, "The repository is not set up yet."))
            #expect(await rig.submit(rig.request()) == .refused(message: "The repository is not set up yet."))
            #expect(rig.inbox.isEmpty)
        }
    }

    @Test func aHostileRefusalIsBounded() async throws {
        let rig = try await makeRig()
        let hostile = String(repeating: "line\nbreak ", count: 10_000)
        await rig.transport.on(storeRoute, refusal(422, hostile))
        guard case .refused(let message) = await rig.submit(rig.request()) else {
            Issue.record("expected a refusal")
            return
        }
        #expect(message.count <= 200)
        #expect(!message.contains("\n"))
        #expect(!message.isEmpty)

        let empty = try await makeRig()
        await empty.transport.on(storeRoute, refusal(422, ""))
        #expect(await empty.submit(empty.request()) == .refused(message: ""))
    }

    @Test func aTimeoutKeepsTheItem() async throws {
        let rig = try await makeRig()
        await rig.transport.hold(storeRoute)
        let outcome = await rig.advancing { await rig.submit(rig.request(), budget: .seconds(10)) }
        #expect(outcome == .saved)
        #expect(rig.inbox.count == 1)
    }

    @Test func offlineKeepsTheItem() async throws {
        let rig = try await makeRig()
        await rig.transport.on(storeRoute, .failure(.notConnectedToInternet))
        #expect(await rig.submit(rig.request()) == .saved)
        #expect(rig.inbox.count == 1)
    }

    @Test func aServerErrorKeepsTheItem() async throws {
        let rig = try await makeRig()
        await rig.transport.on(storeRoute, .json(500, #"{"error":"server_error","message":"No."}"#))
        #expect(await rig.submit(rig.request()) == .saved)
        #expect(rig.inbox.count == 1)
    }

    @Test func invalidInputWritesNothing() async throws {
        let rig = try await makeRig()
        let cases: [(StartThreadRequest, StartThreadProblem)] = [
            (rig.request(text: ""), .emptyText),
            (rig.request(text: " \n\t "), .emptyText),
            (rig.request(text: String(repeating: "a", count: 4001)), .textTooLong),
            (rig.request(repo: "../etc"), .unknownRepository),
            (rig.request(repo: "a/b/c"), .unknownRepository),
            (rig.request(repo: "a/.."), .unknownRepository),
            (rig.request(repo: "no-slash"), .unknownRepository),
            (rig.request(repo: "a b/c"), .unknownRepository),
            (StartThreadRequest(instance: UUID(), repo: "example/api", mode: "fix", text: "Hi"), .noInstance),
        ]
        for (request, problem) in cases {
            #expect(await rig.submit(request) == .invalid(problem))
        }
        #expect(rig.inbox.isEmpty)
        #expect(await rig.transport.requests.isEmpty)
        #expect(await rig.submit(rig.request(text: String(repeating: "a", count: 4000))) != .invalid(.textTooLong))
    }

    @Test func aRevokedInstanceWritesNothing() async throws {
        let rig = try await makeRig()
        try rig.tokens.removeToken(for: rig.instance.id)
        #expect(await rig.submit(rig.request()) == .needsPairing)
        #expect(rig.inbox.isEmpty)
        #expect(await rig.transport.requests.isEmpty)
    }

    @Test func repositoriesAreBounded() async throws {
        let rig = try await makeRig()
        var repos: [String] = (0..<500).map { index in
            #"{"slug":"example/r\#(index)","name":"r\#(index)","setupStatus":"\#(index >= 400 ? "ready" : "running")"}"#
        }
        repos.append(
            #"{"slug":"example/long","name":"\#(String(repeating: "n", count: 10_000))","setupStatus":"ready"}"#)
        repos.append(#"{"slug":"../etc","name":"x","setupStatus":"ready"}"#)
        repos.append(#"{"slug":"a/b/c","name":"x","setupStatus":"ready"}"#)
        repos.append(#"{"slug":"example/\#(String(repeating: "s", count: 94))","name":"x","setupStatus":"ready"}"#)
        let body = """
            {"repos":[\(repos.joined(separator: ","))],"picker":{"accounts":[],"defaultsByRepo":{}},\
            "defaultRepo":"a/b/c","models":[]}
            """
        await rig.transport.on(optionsRoute, .json(200, body))
        let result = try await rig.submission.repositories(instance: rig.instance.id, budget: .seconds(8))
        #expect(result.repositories.count == 200)
        #expect(result.repositories.first?.isReady == true)
        #expect(result.repositories.allSatisfy { $0.slug.count <= 100 && $0.name.count <= 100 })
        #expect(!result.repositories.contains { ["../etc", "a/b/c"].contains($0.slug) })
        #expect(result.repositories.first { $0.slug == "example/long" }?.name.count == 100)
        #expect(result.defaultSlug == nil)
    }

    @Test func theDefaultSlugIsKeptWhenListed() async throws {
        let rig = try await makeRig()
        await rig.transport.on(optionsRoute, try .scenario(200, "scenario-thread-new"))
        let result = try await rig.submission.repositories(instance: rig.instance.id, budget: .seconds(8))
        #expect(result.repositories.map(\.slug) == ["example/api", "example/web"])
        #expect(result.repositories.map(\.isReady) == [true, false])
        #expect(result.defaultSlug == "example/api")
    }

    @Test func repositoryFailuresMapToStates() async throws {
        func failure(_ result: FakeTransport.Result?, hold: Bool = false) async throws -> StartThreadOptionsFailure? {
            let rig = try await makeRig()
            if let result { await rig.transport.on(optionsRoute, result) }
            if hold { await rig.transport.hold(optionsRoute) }
            // A failing GET waits out its retry delays on the manual clock; a budget far beyond them keeps the
            // answer from racing the budget, and only the held request meets its budget.
            let budget: Duration = hold ? .seconds(8) : .seconds(100_000)
            let load: @Sendable () async -> StartThreadOptionsFailure? = {
                do throws(StartThreadOptionsFailure) {
                    _ = try await rig.submission.repositories(instance: rig.instance.id, budget: budget)
                    return nil
                } catch { return error }
            }
            return await rig.advancing(load)
        }
        #expect(try await failure(.json(401, #"{"error":"invalid_token","message":"No."}"#)) == .needsPairing)
        #expect(try await failure(.failure(.notConnectedToInternet)) == .offline)
        #expect(try await failure(.json(500, #"{"error":"server_error","message":"No."}"#)) == .unavailable)
        #expect(try await failure(nil, hold: true) == .timedOut)

        let rig = try await makeRig()
        do {
            _ = try await rig.submission.repositories(instance: UUID(), budget: .seconds(8))
            Issue.record("expected a failure")
        } catch { #expect(error == .noInstance) }
        #expect(await rig.transport.requests.isEmpty)
    }
}

private final class Locked<Value: Sendable>: @unchecked Sendable {
    private let lock = NSLock()
    private var stored: Value
    init(_ value: Value) { stored = value }
    var value: Value { lock.withLock { stored } }
    func mutate(_ change: (inout Value) -> Void) { lock.withLock { change(&stored) } }
}
