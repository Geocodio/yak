import Foundation
import YakKit

@testable import Yak

final class FilesMemoryDefaults: KeyValueStore, @unchecked Sendable {
    private let lock = NSLock()
    private var values: [String: Data] = [:]
    func data(forKey key: String) -> Data? { lock.withLock { values[key] } }
    func set(_ value: Data?, forKey key: String) { lock.withLock { values[key] = value } }
}

struct FilesNoSockets: WebSocketConnector {
    struct Refused: Error {}
    func connect(to url: URL) async throws -> any WebSocketConnection { throw Refused() }
}

/// Serves one thread with a small file tree, one text file and a save that answers as the test says.
final class FilesTransport: HTTPTransport, @unchecked Sendable {
    enum SaveAnswer { case saved, conflict, offline }

    private let lock = NSLock()
    private var log: [String] = []
    private var saveAnswer = SaveAnswer.saved
    private var contentValue = "<?php\nclass Importer {}\n"
    private var restoreCount = 0
    var canEdit = true
    private var contentOffline = false
    func setContentOffline(_ value: Bool) { lock.withLock { contentOffline = value } }

    var requests: [String] { lock.withLock { log } }
    func count(_ prefix: String) -> Int { requests.filter { $0.hasPrefix(prefix) }.count }
    func setSaveAnswer(_ answer: SaveAnswer) { lock.withLock { saveAnswer = answer } }
    func setContent(_ value: String) { lock.withLock { contentValue = value } }

    private static let tree = """
        {"root":"/workspace","entries":[
        {"name":"src","path":"src","isDirectory":true,"isWritable":true,"size":null},
        {"name":"README.md","path":"README.md","isDirectory":false,"isWritable":true,"size":12}],
        "changedPaths":["src/Importer.php"],"addedPaths":[],"truncated":false}
        """
    private static let src = """
        {"root":"/workspace","entries":[
        {"name":"Importer.php","path":"src/Importer.php","isDirectory":false,"isWritable":true,"size":24}],
        "changedPaths":["src/Importer.php"],"addedPaths":[],"truncated":false}
        """

    private func file(_ sha: String, _ content: String) -> String {
        let escaped = content.replacingOccurrences(of: "\n", with: "\\n")
        return """
            {"path":"src/Importer.php","sha256":"\(sha)","size":\(content.utf8.count),"isBinary":false,"isTooLarge":false,
            "content":"\(escaped)","headContent":null,"language":"php","canEdit":\(canEdit)}
            """
    }

    func send(_ request: URLRequest) async throws -> (Data, HTTPURLResponse) {
        let url = request.url!
        let path = url.path
        let method = request.httpMethod ?? "GET"
        let query = url.query ?? ""
        lock.withLock { log.append("\(method) \(path)?\(query)") }
        var status = 200
        let body: String
        if path.hasSuffix("/me") {
            body = """
                {"user":{"id":7,"name":"Test Person","email":"person@example.test","initials":"TP","isAdmin":false},
                "device":{"id":3,"name":"Test phone","kind":"phone","pairedAt":"2026-11-15T08:00:00Z"},
                "instance":{"name":"Example Yak","url":"https://example.test","version":"2026.11.1"},"needsYouCount":0,
                "realtime":null,"terminal":{"wsUrl":"wss://example.test/terminal"},
                "attachments":{"maxFiles":8,"maxFileBytes":10485760},
                "push":{"apns":false,"bundleId":"com.example.yak","environment":"production"},
                "features":{"liveActivities":false,"widgets":false}}
                """
        } else if path.hasSuffix("/files/search") {
            body = #"{"matches":[{"name":"Importer.php","path":"src/Importer.php"}],"truncated":false}"#
        } else if path.hasSuffix("/files/content"), method == "PUT" {
            switch lock.withLock({ saveAnswer }) {
            case .saved:
                body = """
                    {"state":"saved","file":{"path":"src/Importer.php","sha256":"bbbb","size":10,"isBinary":false,
                    "isTooLarge":false,"content":"saved","headContent":null,"language":"php"},"working":true}
                    """
            case .conflict:
                status = 409
                let theirs = file("cccc", "<?php\nclass Theirs {}\n").replacingOccurrences(
                    of: #","canEdit":true"#, with: "")
                body =
                    #"{"error":"file_conflict","message":"The file changed.","details":{"state":"conflict","file":\#(theirs)}}"#
            case .offline:
                throw URLError(.notConnectedToInternet)
            }
        } else if path.hasSuffix("/files/content") {
            if lock.withLock({ contentOffline }) { throw URLError(.notConnectedToInternet) }
            body = file("aaaa", lock.withLock { contentValue })
        } else if path.hasSuffix("/files") {
            body = query.contains("path=src") ? Self.src : Self.tree
        } else if path.hasSuffix("restore/retry") {
            lock.withLock { restoreCount += 1 }
            try await Task.sleep(for: .milliseconds(150))
            body = #"{"status":"ok"}"#
        } else if path.hasSuffix("threads/d0e515ef") {
            body = """
                {"thread":{"id":"d0e515ef","name":"retry-failed-rows","title":"Retry failed rows","repo":"example/api",
                "kind":"root","status":"open","visibility":"team","branch":null,"sandboxState":"running",
                "runnerState":"idle","blockedReason":null,"parentThreadId":null,"rootThreadId":null,
                "lastActivityAt":"2026-11-15T09:30:00Z","pullRequestCount":0,"isOwner":true,"isStarred":false},
                "canDrive":true,"messages":[],"composer":{"state":"idle","model":"sonnet","accountName":"Test account",
                "nextAttachmentNumber":1,"models":[]},"notice":null,
                "panes":{"chat":true,"preview":false,"terminal":true,"changes":true,"files":true},"question":null,
                "latestSequence":14}
                """
        } else if path.hasSuffix("events") {
            body = #"{"events":[],"latestSequence":14,"source":"database"}"#
        } else {
            throw URLError(.cancelled)
        }
        let response = HTTPURLResponse(
            url: url, statusCode: status, httpVersion: nil,
            headerFields: ["Content-Type": "application/json", "X-Yak-Protocol": "1"])!
        return (Data(body.utf8), response)
    }
}

@MainActor struct FilesFixture {
    let transport = FilesTransport()
    let session: InstanceSession
    let thread: ThreadModel
    let model: FilesEverythingModel

    init() async throws {
        let transport = transport
        let tokens = InMemoryTokenStore()
        let instances = InstanceStore(defaults: FilesMemoryDefaults(), tokens: tokens, now: { Date() })
        let instance = try await instances.add(
            url: URL(string: "https://example.test")!, name: "Example Yak", userName: "Test Person", token: "token")
        session = InstanceSession(
            instance: instance, instances: instances, tokens: tokens,
            environment: SessionEnvironment(
                http: transport, webSockets: FilesNoSockets(), files: InMemoryFileStore(), clock: SystemClock(),
                makeID: { UUID() }, appVersion: "1.0.0", systemDescription: "Test", allowsInsecureRealtime: false))
        await session.start()
        thread = ThreadModel(threadID: "d0e515ef", session: session)
        await thread.start(initialTitle: nil)
        model = FilesEverythingModel(thread: thread, timings: .standard)
        for _ in 0..<600 where thread.snapshot?.show == nil { try? await Task.sleep(for: .milliseconds(5)) }
    }

    func eventually(_ condition: () -> Bool) async -> Bool {
        for _ in 0..<600 {
            if condition() { return true }
            try? await Task.sleep(for: .milliseconds(5))
        }
        return condition()
    }

    func finish() async {
        await model.stop()
        await thread.stop()
        await session.stop()
    }
}
