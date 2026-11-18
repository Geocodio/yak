import AppIntents
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

private let privateTitle = "Private task about payroll"

private func row(_ id: String, _ title: String, kind: String = "thread", isPrivate: Bool = false) -> String {
    let base = "\"title\":\"\(title)\",\"isPrivate\":\(isPrivate),\"needsYouReason\":\"question\""
    if kind == "task" { return "{\"type\":\"task\",\"taskId\":\"\(id)\",\(base)}" }
    return """
        {"id":"\(id)","repo":"example/api","status":"running","needsYouLabel":"Asked you a question",\
        "isShared":false,"isUnattended":false,"isStarred":false,"canDelete":true,"group":"today","children":[],\(base)}
        """
}

private func list(count: Int, items: [String]) -> String {
    "{\"needsYou\":[\(items.joined(separator: ","))],\"threads\":[],\"needsYouCount\":\(count),\"hasAnyThreads\":true}"
}

@Suite struct IntentTests {
    private let transport = RecordingTransport()

    private func make(instances count: Int = 1, removingTokens: Bool = false) async throws -> (
        IntentRunner, [Instance], InMemoryFileStore
    ) {
        let tokens = InMemoryTokenStore()
        let store = InstanceStore(defaults: MemoryDefaults(), tokens: tokens, now: { Date() })
        var added: [Instance] = []
        let hosts = ["https://yak.example.com", "https://other.example.com"]
        let names = ["Example Yak", "Other Yak"]
        for index in 0..<count {
            added.append(
                try await store.add(
                    url: URL(string: hosts[index])!, name: names[index], userName: "Test person", token: "t\(index)"))
        }
        if removingTokens { for instance in added { try tokens.removeToken(for: instance.id) } }
        let files = InMemoryFileStore()
        let environment = SessionEnvironment(
            http: transport, webSockets: URLSessionWebSocketConnector(), files: files, clock: SystemClock(),
            makeID: UUID.init, appVersion: "1", systemDescription: "Test", allowsInsecureRealtime: false)
        let services = IntentServicesValue(
            instances: store, environment: environment, tokens: tokens, lastRepository: { _ in nil })
        return (IntentRunner(services: services), added, files)
    }

    @Test func everyIntentRequiresAuthentication() {
        #expect(StartThreadIntent.authenticationPolicy == .requiresAuthentication)
        #expect(NeedsMeIntent.authenticationPolicy == .requiresAuthentication)
        #expect(OpenLastThreadIntent.authenticationPolicy == .requiresAuthentication)
        #expect(OpenThreadIntent.authenticationPolicy == .requiresAuthentication)
    }

    @Test func startingAThreadSaysWhereItStarted() async throws {
        await transport.on("POST threads", status: 201, body: "{\"id\":\"k7m2q9rt\"}")
        let (runner, instances, _) = try await make()
        let answer = await runner.startThread(
            instance: instances[0].id, repository: "example/api", mode: "fix", text: "Add a retry")
        #expect(answer.dialog == "Started a thread in example/api.")
        let thread = try #require(answer.thread)
        #expect(thread.id.hasSuffix("/thread/k7m2q9rt"))
        #expect(thread.title == "New thread in example/api" && thread.subtitle == "Example Yak")
        #expect(answer.link == nil, "starting a thread opens nothing")
        #expect(!answer.dialog.contains("Add a retry"), "the dialog never repeats the text")
    }

    @Test func aRepositoryFailureSaysWhatToDo() async throws {
        let (runner, instances, _) = try await make()
        await transport.on("GET threads/new", status: 500, body: "{\"error\":\"server_error\",\"message\":\"x\"}")
        var answer = await runner.startThread(instance: nil, repository: nil, mode: "fix", text: "Add a retry")
        #expect(answer.dialog == "Yak could not load your repositories. Try again in a moment.")
        await transport.on("GET threads/new", status: 401, body: "{\"error\":\"device_revoked\",\"message\":\"x\"}")
        answer = await runner.startThread(instance: instances[0].id, repository: nil, mode: "fix", text: "Add a retry")
        #expect(answer.dialog == "Pair yak.example.com again in Yak.")
        await transport.setOffline(true)
        answer = await runner.startThread(instance: nil, repository: nil, mode: "fix", text: "Add a retry")
        #expect(answer.dialog == "Yak could not reach yak.example.com. Try again when you are online.")
    }

    @Test func noInstanceSaysToPair() async throws {
        let (runner, _, _) = try await make(instances: 0)
        let answer = await runner.startThread(
            instance: nil, repository: "example/api", mode: "fix", text: "Add a retry")
        #expect(answer.dialog == "Pair Yak with an instance first.")
        #expect(await transport.routes().isEmpty)
    }

    @Test func aHostileRefusalIsShortInTheDialog() async throws {
        let message = String(repeating: "Line one\nLine two ", count: 6000)
        let body = try String(
            decoding: JSONSerialization.data(withJSONObject: ["error": "validation_failed", "message": message]),
            as: UTF8.self)
        await transport.on("POST threads", status: 422, body: body)
        let (runner, _, _) = try await make()
        let answer = await runner.startThread(
            instance: nil, repository: "example/api", mode: "fix", text: "Add a retry")
        #expect(answer.dialog.count <= 200 && !answer.dialog.contains("\n"))
    }

    @Test func aRefusalSpeaksTheServersMessage() async throws {
        await transport.on(
            "POST threads", status: 422,
            body: "{\"error\":\"validation_failed\",\"message\":\"The repository is not set up yet.\"}")
        let (runner, _, _) = try await make()
        let answer = await runner.startThread(
            instance: nil, repository: "example/api", mode: "fix", text: "Add a retry")
        #expect(answer.dialog == "The repository is not set up yet.")
    }

    @Test func startingOfflineSaysItWasSaved() async throws {
        await transport.setOffline(true)
        let (runner, _, files) = try await make()
        let answer = await runner.startThread(
            instance: nil, repository: "example/api", mode: "fix", text: "Add a retry")
        #expect(answer.dialog == "Saved. Yak starts the thread the next time it is online.")
        #expect(answer.thread == nil)
        #expect(!files.has("no such file"), "the store answers")
    }

    @Test func anEmptyTextIsRefusedWithoutAWrite() async throws {
        let (runner, _, _) = try await make()
        let answer = await runner.startThread(instance: nil, repository: "example/api", mode: "fix", text: "   ")
        #expect(answer.dialog == "Tell Yak what to work on.")
        #expect(await transport.routes().isEmpty)
    }

    @Test func needsMeSpeaksCountsAndNeverATitle() async throws {
        let items = [
            row("d0e515ef", "Retry failed rows"), row("k3m9p2qa", privateTitle, kind: "task", isPrivate: true),
        ]
        await transport.on("GET threads", status: 200, body: list(count: 2, items: items))
        let (runner, _, _) = try await make(instances: 2)
        let answer = await runner.needsMe(instance: nil)
        #expect(answer.dialog.hasPrefix("4 things need you: 2 on "))
        #expect(answer.dialog.contains("2 on Example Yak") && answer.dialog.contains("2 on Other Yak"))
        #expect(!answer.dialog.contains("Retry") && !answer.dialog.contains("payroll"))
        let titles = answer.rows.map(\.title)
        #expect(titles.contains("A private thread") && !titles.joined().contains("payroll"))
    }

    @Test func needsMeForOneInstance() async throws {
        await transport.on(
            "GET threads", status: 200, body: list(count: 1, items: [row("d0e515ef", "Retry failed rows")]))
        let (runner, instances, _) = try await make()
        #expect(await runner.needsMe(instance: instances[0].id).dialog == "One thing needs you on Example Yak.")
        await transport.on("GET threads", status: 200, body: list(count: 0, items: []))
        #expect(await runner.needsMe(instance: instances[0].id).dialog == "Nothing needs you on Example Yak.")
    }

    @Test func needsMeSaysWhichInstanceWasUnreachable() async throws {
        await transport.setOffline(true)
        let (runner, _, _) = try await make()
        let answer = await runner.needsMe(instance: nil)
        #expect(answer.dialog.contains("Example Yak could not be reached, so its count may be old."))
    }

    @Test func openLastThreadWithNoneSaysSo() async throws {
        let (runner, _, _) = try await make()
        let answer = await runner.lastThread(instance: nil)
        #expect(answer.dialog == "You have not opened a thread on Example Yak yet." && answer.link == nil)
    }

    @Test func openLastThreadOpensItsLink() async throws {
        let (runner, instances, files) = try await make()
        let cache = Cache(instance: instances[0].id, files: files, clock: SystemClock())
        await cache.markOpened("d0e515ef")
        let answer = await runner.lastThread(instance: nil)
        let link = try #require(answer.link)
        let components = URLComponents(url: link, resolvingAgainstBaseURL: false)
        #expect(components?.scheme == "yak" && components?.host == "thread")
        #expect(components?.queryItems?.first { $0.name == "id" }?.value == "d0e515ef")
        #expect(answer.thread?.link == link)
    }

    @Test func needsMeForAnInstanceThatNeedsPairingSaysPairAgain() async throws {
        let (runner, _, _) = try await make(removingTokens: true)
        let answer = await runner.needsMe(instance: nil)
        #expect(answer.dialog.contains("Pair yak.example.com again in Yak."))
        #expect(!answer.dialog.contains("could not be reached"))
    }

    @Test func needsMeForARemovedInstanceSaysSo() async throws {
        let (runner, _, _) = try await make()
        #expect(await runner.needsMe(instance: UUID()).dialog == "That Yak is no longer paired.")
    }

    @Test func entityIDsResolveWithTheirLink() async throws {
        let (runner, instances, _) = try await make()
        let value = try #require(await runner.entity(for: "\(instances[0].id.uuidString)/thread/d0e515ef"))
        let components = URLComponents(url: value.link, resolvingAgainstBaseURL: false)
        #expect(components?.host == "thread")
        #expect(components?.queryItems?.first { $0.name == "id" }?.value == "d0e515ef")
        #expect(await runner.entity(for: "\(instances[0].id.uuidString)/thread/../../x") == nil)
        #expect(await runner.entity(for: "nonsense") == nil)
    }
}
