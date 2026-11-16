import Foundation
import Testing
import YakKit

@testable import Yak

private final class MemoryDefaults: KeyValueStore, @unchecked Sendable {
    private let lock = NSLock()
    private var values: [String: Data] = [:]
    func data(forKey key: String) -> Data? { lock.withLock { values[key] } }
    func set(_ value: Data?, forKey key: String) { lock.withLock { values[key] = value } }
}

private struct NoSockets: WebSocketConnector {
    struct Refused: Error {}
    func connect(to url: URL) async throws -> any WebSocketConnection { throw Refused() }
}

/// Answers the routes of the new-thread screen; each answer can be changed per test.
private final class NewThreadTransport: HTTPTransport, @unchecked Sendable {
    private let lock = NSLock()
    private var paths: [String] = []
    private var bodies: [String: Data] = [:]
    var optionsDelay: Duration = .zero
    var accessDelay: Duration = .zero
    var optionsAnswer: (status: Int, body: String)? = (200, NewThreadTransport.options)
    var startAnswer: (status: Int, body: String)? = (201, #"{"id":"k7m2q9rt"}"#)
    var accessAnswers: [String: String] = [:]

    static let me = """
        {"user":{"id":7,"name":"Test Person","email":"person@example.test","initials":"TP","isAdmin":false},
        "device":{"id":3,"name":"Test phone","kind":"phone","pairedAt":"2026-11-15T08:00:00Z"},
        "instance":{"name":"Example Yak","url":"https://example.test","version":"2026.11.1"},"needsYouCount":0,
        "realtime":null,"terminal":{"wsUrl":"wss://example.test/terminal"},
        "attachments":{"maxFiles":2,"maxFileBytes":1000},
        "push":{"apns":false,"bundleId":"com.example.yak","environment":"production"},
        "features":{"liveActivities":false,"widgets":false}}
        """

    static let options = """
        {"repos":[{"slug":"example/api","name":"api","setupStatus":"ready","setupStep":null},
        {"slug":"example/web","name":"web","setupStatus":"running","setupStep":"Installing dependencies"},
        {"slug":"example/docs","name":"docs","setupStatus":"ready","setupStep":null}],
        "picker":{"accounts":[{"id":3,"name":"Test account","kind":"team","icon":"users","color":"slate",
        "isSystem":false,"isDefault":true,"defaultModel":"sonnet","usable":true,"status":"ok"}],
        "defaultsByRepo":{"example/api":{"accountId":3,"brokenDefaultAccountId":null,"model":null}},"models":[]},
        "defaultRepo":"example/api",
        "models":[{"family":"sonnet","latest":{"value":"sonnet","label":"Sonnet 5.5","description":"Fast"},
        "older":[]}]}
        """

    func requestCount(_ route: String) -> Int { lock.withLock { paths.filter { $0 == route }.count } }
    func lastBody(_ route: String) -> String? {
        lock.withLock { bodies[route].map { String(decoding: $0, as: UTF8.self) } }
    }

    func send(_ request: URLRequest) async throws -> (Data, HTTPURLResponse) {
        let url = request.url!
        let path = url.path.replacingOccurrences(of: "/api/app/v1/", with: "")
        let route = "\(request.httpMethod ?? "GET") \(path)"
        lock.withLock {
            paths.append(route)
            bodies[route] = request.httpBody
        }
        var status = 200
        var body = ""
        switch route {
        case "GET me": body = Self.me
        case "GET threads/new":
            if optionsDelay > .zero { try await Task.sleep(for: optionsDelay) }
            guard let answer = optionsAnswer else { throw URLError(.notConnectedToInternet) }
            (status, body) = answer
        case "POST threads":
            guard let answer = startAnswer else { throw URLError(.notConnectedToInternet) }
            (status, body) = answer
        case "GET threads/new/repository-access":
            if accessDelay > .zero { try await Task.sleep(for: accessDelay) }
            let repo = URLComponents(url: url, resolvingAgainstBaseURL: false)?.queryItems?.first { $0.name == "repo" }?
                .value
            body = accessAnswers[repo ?? ""] ?? #"{"allowed":true,"reason":null}"#
        case "GET threads":
            body = #"{"needsYou":[],"starred":[],"threads":[],"needsYouCount":0,"hasAnyThreads":false}"#
        default: throw URLError(.cancelled)
        }
        let response = HTTPURLResponse(
            url: url, statusCode: status, httpVersion: nil,
            headerFields: ["Content-Type": "application/json", "X-Yak-Protocol": "1"])!
        return (Data(body.utf8), response)
    }
}

@MainActor private struct Fixture {
    let transport = NewThreadTransport()
    let session: InstanceSession
    let scene = SceneModel(state: SceneState(isNewThreadOpen: true))
    let defaults = UserDefaults(suiteName: UUID().uuidString)!

    init(configure: (NewThreadTransport) -> Void = { _ in }) async throws {
        configure(transport)
        let tokens = InMemoryTokenStore()
        let instances = InstanceStore(defaults: MemoryDefaults(), tokens: tokens, now: { Date() })
        let instance = try await instances.add(
            url: URL(string: "https://example.test")!, name: "Example Yak", userName: "Test Person", token: "token")
        session = InstanceSession(
            instance: instance, instances: instances, tokens: tokens,
            environment: SessionEnvironment(
                http: transport, webSockets: NoSockets(), files: InMemoryFileStore(), clock: SystemClock(),
                makeID: { UUID() }, appVersion: "1.0.0", systemDescription: "Test", allowsInsecureRealtime: false))
        await session.start()
    }

    func model(
        prefill: (repo: String?, text: String?)? = nil, item: UUID? = nil, slowAfter: Duration = .seconds(8)
    ) -> NewThreadModel {
        NewThreadModel(
            session: session, scene: scene, prefill: prefill, item: item, defaults: defaults, slowAfter: slowAfter)
    }

    func eventually(_ condition: () -> Bool) async -> Bool {
        for _ in 0..<800 {
            if condition() { return true }
            try? await Task.sleep(for: .milliseconds(5))
        }
        return condition()
    }
}

@MainActor @Test func startsFromTheDefaults() async throws {
    let fixture = try await Fixture()
    let model = fixture.model()
    await model.start()
    #expect(model.load == .ready)
    #expect(model.form.repo == "example/api")
    #expect(model.form.mode == .fix)
    #expect(model.form.model == "sonnet")
    await model.stop()
    await fixture.session.stop()
}

@MainActor @Test func theLastUsedRepositoryComesFirst() async throws {
    let fixture = try await Fixture()
    fixture.defaults.set("example/docs", forKey: NewThreadModel.lastRepositoryKey(fixture.session.instance.id))
    let model = fixture.model()
    #expect(model.form.repo == "example/docs")
    await model.start()
    #expect(model.form.repo == "example/docs")
    await model.stop()
    await fixture.session.stop()
}

@MainActor @Test func aProblemBlocksTheSend() async throws {
    let fixture = try await Fixture()
    let model = fixture.model()
    await model.start()
    model.text = "   "
    await model.send()
    #expect(model.problem == .noText)
    #expect(model.refusedSendCount == 1)
    #expect(fixture.transport.requestCount("POST threads") == 0)
    #expect(model.pending == nil)
    await model.stop()
    await fixture.session.stop()
}

@MainActor @Test func aRepositoryThatIsNotReadyBlocksTheSend() async throws {
    let fixture = try await Fixture()
    let model = fixture.model()
    await model.start()
    model.selectRepository("example/web")
    model.text = "Fix the importer"
    await model.send()
    #expect(model.problem == .repositoryNotReady(slug: "example/web", step: "Installing dependencies"))
    #expect(fixture.transport.requestCount("POST threads") == 0)
    await model.stop()
    await fixture.session.stop()
}

@MainActor @Test func aRefusedExtraRepositoryBlocksTheSend() async throws {
    let fixture = try await Fixture {
        $0.accessAnswers["example/docs"] = #"{"allowed":false,"reason":"You cannot use this repository."}"#
    }
    let model = fixture.model()
    await model.start()
    model.text = "Fix the importer"
    model.addExtraRepository("example/docs", role: "context")
    await model.send()
    #expect(model.problem == .extraRepositoryRefused(slug: "example/docs", reason: "You cannot use this repository."))
    #expect(fixture.transport.requestCount("POST threads") == 0)
    await model.stop()
    await fixture.session.stop()
}

@MainActor @Test func aDeliveryOpensTheNewThread() async throws {
    let fixture = try await Fixture()
    let model = fixture.model()
    await model.start()
    model.text = "Fix the importer"
    await model.send()
    #expect(await fixture.eventually { fixture.scene.state.threadID == "k7m2q9rt" })
    #expect(!fixture.scene.state.isNewThreadOpen)
    #expect(await fixture.session.composerDraft(.newThread) == nil)
    #expect(fixture.transport.requestCount("POST threads") == 1)
    #expect(
        fixture.defaults.string(forKey: NewThreadModel.lastRepositoryKey(fixture.session.instance.id)) == "example/api")
    await model.stop()
    #expect(await fixture.session.composerDraft(.newThread) == nil)
    await fixture.session.stop()
}

@MainActor @Test func offlineQueuesWithTheSavedRepository() async throws {
    let fixture = try await Fixture {
        $0.optionsAnswer = nil
        $0.startAnswer = nil
    }
    await fixture.session.saveComposerDraft(
        ComposerDraft(text: "Fix the importer", newThread: NewThreadDraft(repo: "example/api", mode: "fix")),
        for: .newThread)
    let model = fixture.model()
    await model.start()
    #expect(model.isOffline)
    #expect(model.text == "Fix the importer")
    await model.send()
    #expect(model.problem == nil)
    let item = try #require(model.pending)
    #expect(item.text == "Fix the importer")
    #expect(await fixture.eventually { if case .waiting = model.pending?.state { true } else { false } })
    #expect(fixture.scene.state.threadID == nil)
    await model.stop()
    await fixture.session.stop()
}

@MainActor @Test func withoutARepositoryOfflineTheSendIsBlocked() async throws {
    let fixture = try await Fixture { $0.optionsAnswer = nil }
    let model = fixture.model()
    await model.start()
    model.text = "Fix the importer"
    await model.send()
    #expect(model.problem == .optionsNotLoaded)
    #expect(model.text == "Fix the importer")
    await model.stop()
    await fixture.session.stop()
}

@MainActor @Test func aFailedLoadKeepsTheTextAndRetryRecovers() async throws {
    let fixture = try await Fixture { $0.optionsAnswer = (500, #"{"error":"server_error","message":"Try again."}"#) }
    let model = fixture.model()
    model.text = "Fix the importer"
    await model.start()
    guard case .failed(.server) = model.load else {
        Issue.record("expected a server failure, got \(model.load)")
        return
    }
    #expect(model.text == "Fix the importer")
    fixture.transport.optionsAnswer = (200, NewThreadTransport.options)
    await model.retry()
    #expect(model.load == .ready)
    #expect(model.text == "Fix the importer")
    await model.stop()
    await fixture.session.stop()
}

@MainActor @Test func aSlowLoadShowsTheSlowState() async throws {
    let fixture = try await Fixture { $0.optionsDelay = .milliseconds(400) }
    let model = fixture.model(slowAfter: .milliseconds(30))
    let starting = Task { await model.start() }
    #expect(await fixture.eventually { model.load == .slow })
    await starting.value
    #expect(model.load == .ready)
    await model.stop()
    await fixture.session.stop()
}

@MainActor @Test func theDraftSurvivesLeavingTheScreen() async throws {
    let fixture = try await Fixture()
    let first = fixture.model()
    await first.start()
    first.text = "Fix the importer"
    first.form.mode = .research
    await first.stop()
    let second = fixture.model()
    await second.start()
    #expect(second.text == "Fix the importer")
    #expect(second.form.mode == .research)
    await second.stop()
    await fixture.session.stop()
}

@MainActor @Test func aLinkFillsTheRepositoryAndText() async throws {
    let fixture = try await Fixture()
    let model = fixture.model(prefill: ("example/docs", "Update the readme"))
    await model.start()
    #expect(model.form.repo == "example/docs")
    #expect(model.text == "Update the readme")
    await model.stop()
    await fixture.session.stop()
}

@MainActor @Test func editBringsARefusedItemBack() async throws {
    let fixture = try await Fixture {
        $0.startAnswer = (422, #"{"error":"validation_failed","message":"The description is too long."}"#)
    }
    let model = fixture.model()
    await model.start()
    model.text = "Fix the importer"
    model.form.mode = .research
    await model.attach(Data("log".utf8), fileName: "run.log", contentType: "text/plain", isImage: false)
    #expect(model.attachments.count == 1)
    await model.send()
    #expect(
        await fixture.eventually {
            if case .refused = model.pending?.state { true } else { false }
        })
    #expect(model.isLocked)
    await model.edit()
    #expect(model.pending == nil)
    #expect(!model.isLocked)
    #expect(model.text == "Fix the importer")
    #expect(model.form.mode == .research)
    #expect(model.attachments.map(\.fileName) == ["run.log"])
    let stored = try #require(model.attachments.first)
    #expect(await fixture.session.attachmentData(stored) == Data("log".utf8))
    #expect(await fixture.session.outbox()?.items().isEmpty == true)
    await model.stop()
    await fixture.session.stop()
}

@MainActor @Test func removeThrowsTheRefusedItemAway() async throws {
    let fixture = try await Fixture {
        $0.startAnswer = (422, #"{"error":"validation_failed","message":"The description is too long."}"#)
    }
    let model = fixture.model()
    await model.start()
    model.text = "Fix the importer"
    await model.send()
    #expect(
        await fixture.eventually {
            if case .refused = model.pending?.state { true } else { false }
        })
    await model.remove()
    #expect(model.pending == nil)
    #expect(model.text.isEmpty)
    #expect(await fixture.session.outbox()?.items().isEmpty == true)
    await model.stop()
    await fixture.session.stop()
}

@MainActor @Test func anItemOfAnEarlierSendComesBackToTheScreen() async throws {
    let fixture = try await Fixture {
        $0.startAnswer = (422, #"{"error":"validation_failed","message":"The description is too long."}"#)
    }
    let first = fixture.model()
    await first.start()
    first.text = "Fix the importer"
    await first.send()
    let id = try #require(first.pending?.id)
    #expect(
        await fixture.eventually {
            if case .refused = first.pending?.state { true } else { false }
        })
    await first.stop()
    let second = fixture.model(item: id)
    await second.start()
    #expect(second.pending?.id == id)
    #expect(second.text == "Fix the importer")
    #expect(second.isLocked)
    await second.stop()
    await fixture.session.stop()
}

@MainActor @Test func attachmentsRespectTheInstanceLimits() async throws {
    let fixture = try await Fixture()
    let model = fixture.model()
    await model.start()
    await model.attach(Data(count: 2000), fileName: "big.bin", contentType: "application/octet-stream", isImage: false)
    #expect(model.attachmentFailure == .tooLarge(maximumBytes: 1000))
    #expect(model.attachments.isEmpty)
    await model.attach(Data(count: 10), fileName: "a.txt", contentType: "text/plain", isImage: false)
    await model.attach(Data(count: 10), fileName: "b.txt", contentType: "text/plain", isImage: false)
    await model.attach(Data(count: 10), fileName: "c.txt", contentType: "text/plain", isImage: false)
    #expect(model.attachmentFailure == .tooMany(maximum: 2))
    #expect(model.attachments.map(\.reference) == ["File #1", "File #2"])
    await model.stop()
    await fixture.session.stop()
}

@MainActor @Test func retryAfterTheNetworkReturnsLoadsTheRepositories() async throws {
    let fixture = try await Fixture { $0.optionsAnswer = nil }
    let model = fixture.model()
    model.text = "Fix the importer"
    await model.start()
    #expect(model.isOffline)
    #expect(model.options == nil)
    fixture.transport.optionsAnswer = (200, NewThreadTransport.options)
    await model.retry()
    #expect(model.load == .ready)
    #expect(model.form.repo == "example/api")
    #expect(model.text == "Fix the importer")
    await model.stop()
    await fixture.session.stop()
}

@MainActor @Test func twoQuickSendsStartOneThread() async throws {
    let fixture = try await Fixture { $0.accessDelay = .milliseconds(150) }
    let model = fixture.model()
    await model.start()
    model.text = "Fix the importer"
    model.addExtraRepository("example/docs", role: "context")
    async let first: Void = model.send()
    async let second: Void = model.send()
    _ = await (first, second)
    #expect(await fixture.eventually { fixture.scene.state.threadID == "k7m2q9rt" })
    #expect(fixture.transport.requestCount("POST threads") == 1)
    await model.stop()
    await fixture.session.stop()
}

@MainActor @Test func theChipsAloneAreSaved() async throws {
    let fixture = try await Fixture()
    let first = fixture.model()
    await first.start()
    first.form.mode = .research
    await first.stop()
    let second = fixture.model()
    await second.start()
    #expect(second.form.mode == .research)
    #expect(second.text.isEmpty)
    await second.stop()
    await fixture.session.stop()
}

@MainActor @Test func aRefusedSendBumpsTheHaptic() async throws {
    let fixture = try await Fixture {
        $0.startAnswer = (422, #"{"error":"validation_failed","message":"The description is too long."}"#)
    }
    let model = fixture.model()
    await model.start()
    model.text = "Fix the importer"
    await model.send()
    #expect(await fixture.eventually { if case .refused = model.pending?.state { true } else { false } })
    #expect(model.refusedSendCount == 1)
    await model.stop()
    await fixture.session.stop()
}
