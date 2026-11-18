import Foundation
import Testing
import YakKit

@testable import Yak

private final class RecordingSender: ShareSending, @unchecked Sendable {
    private let lock = NSLock()
    private(set) var sent: [(draft: OutboxDraft, staging: String, instance: UUID)] = []
    private(set) var attempts: [OutboxDraft] = []
    var failure: ShareSendProblem?

    func send(_ draft: OutboxDraft, stagingFolder: String, instance: UUID, token: String) async throws(ShareSendProblem)
    {
        lock.withLock { attempts.append(draft) }
        if let failure { throw failure }
        lock.withLock { sent.append((draft, stagingFolder, instance)) }
    }
}

private struct TextProvider: ShareItemProvider {
    var kind: ShareItemKind { .text }
    var suggestedName: String? { nil }
    func loadImage(maximumBytes: Int) async throws -> PreparedAttachment { throw URLError(.badURL) }
    func loadURL() async throws -> URL { throw URLError(.badURL) }
    func loadText(maximumBytes: Int) async throws -> Data { Data("The overlap shows on small screens.".utf8) }
}

private struct ImageProvider: ShareItemProvider {
    let name: String
    var kind: ShareItemKind { .image }
    var suggestedName: String? { name }
    func loadImage(maximumBytes: Int) async throws -> PreparedAttachment {
        PreparedAttachment(data: Data(name.utf8), fileName: name, contentType: "image/jpeg", isImage: true)
    }
    func loadURL() async throws -> URL { throw URLError(.badURL) }
    func loadText(maximumBytes: Int) async throws -> Data { Data() }
}

@MainActor
private struct Rig {
    let model: ShareExtensionModel
    let sender = RecordingSender()
    let files: DiskFileStore
    let instances: InstanceStore
    let tokens = InMemoryTokenStore()

    init(
        withInstance: Bool = true, withToken: Bool = true, withContext: Bool = true, realSender: Bool = false,
        options: NewThreadOptions? = nil
    ) async throws {
        let folder = FileManager.default.temporaryDirectory.appendingPathComponent(UUID().uuidString)
        files = DiskFileStore(root: folder)
        instances = InstanceStore(
            defaults: UserDefaults(suiteName: UUID().uuidString)!, tokens: tokens, now: { Date() })
        let sender = sender
        if withInstance {
            let instance = try await instances.add(
                url: URL(string: "https://yak.example.com")!, name: "Example Yak", userName: "Test person",
                token: "test-token")
            if !withToken { try tokens.removeToken(for: instance.id) }
            if withContext {
                let json = """
                    {"instanceName":"Example Yak","limits":{"maxFiles":8,"maxFileBytes":10485760},
                     "options":{"repos":[{"slug":"example/api","name":"api","setupStatus":"ready","setupStep":null}],
                      "picker":{"accounts":[],"defaultsByRepo":{},"models":[]},"defaultRepo":"example/api","models":[]},
                     "lastRepo":"example/api","updatedAt":800000000}
                    """
                let context = try JSONDecoder().decode(ShareContext.self, from: Data(json.utf8))
                try ShareContextStore.write(context, instance: instance.id, files: files)
            }
        }
        let tokens = tokens
        let used: any ShareSending =
            realSender
            ? ShareSender(
                files: files, appBundleID: "com.example.yak", appGroup: nil, clock: SystemClock(),
                makeClient: { _, _ in nil }) : sender
        model = ShareExtensionModel(
            instances: instances, tokens: tokens, files: files, sender: used, clock: SystemClock(),
            fetchOptions: { _, _ in
                guard let options else { throw APIFailure.network(.notConnectedToInternet) }
                return options
            })
    }
}

@MainActor
struct ShareExtensionModelTests {
    @Test func noInstanceAndSignedOut() async throws {
        let none = try await Rig(withInstance: false)
        await none.model.start([])
        #expect(none.model.phase == .noInstance)
        let out = try await Rig(withToken: false)
        await out.model.start([])
        #expect(out.model.phase == .signedOut("Example Yak"))
        #expect(!out.model.canSend)
    }

    @Test func theContextShowsAtOnceAndOfflineIsNoted() async throws {
        let rig = try await Rig()
        await rig.model.start([TextProvider()])
        #expect(rig.model.phase == .ready)
        #expect(rig.model.context?.instanceName == "Example Yak")
        #expect(rig.model.form?.repo == "example/api")
        #expect(rig.model.isOffline)
        #expect(rig.model.canSend)
    }

    @Test func sendHandsOverOneDraftWithItsKey() async throws {
        let rig = try await Rig()
        await rig.model.start([TextProvider()])
        rig.model.typed = "Look at this"
        let closes = await rig.model.send()
        #expect(closes)
        #expect(rig.sender.sent.count == 1)
        #expect(rig.sender.sent.first?.draft.text.hasPrefix("Look at this") == true)
    }

    @Test func aFailedSendIsReadyAgainWithTheSameKey() async throws {
        let rig = try await Rig()
        rig.sender.failure = .notSaved
        await rig.model.start([TextProvider()])
        rig.model.typed = "Keep me"
        #expect(await rig.model.send() == false)
        #expect(rig.model.phase == .ready)
        #expect(rig.model.didFailToSave)
        #expect(rig.model.canSend)
        #expect(rig.model.typed == "Keep me")
        #expect(await rig.model.send() == false)
        #expect(rig.model.saveFailureCount == 2)
        rig.sender.failure = nil
        #expect(await rig.model.send())
        #expect(!rig.model.didFailToSave)
        let keys = Set(rig.sender.attempts.compactMap(\.sendKey))
        #expect(rig.sender.attempts.count == 3)
        #expect(keys.count == 1)
    }

    @Test func aFailedSendWithTheRealSenderKeepsTheStagedFiles() async throws {
        let rig = try await Rig(realSender: true)
        await rig.model.start([ImageProvider(name: "a.jpg"), ImageProvider(name: "b.jpg")])
        let folder = try #require(try rig.files.list(InstanceFolders.shareStaging).first)
        let staging = "\(InstanceFolders.shareStaging)/\(folder)"
        #expect(try rig.files.list(staging) == ["1-a.jpg", "2-b.jpg"])
        rig.model.typed = "Look at these"
        let second = try #require(try rig.files.read("\(staging)/2-b.jpg"))
        try rig.files.remove("\(staging)/2-b.jpg")
        #expect(await rig.model.send() == false)
        #expect(rig.model.phase == .ready)
        #expect(rig.model.didFailToSave)
        #expect(try rig.files.list(staging) == ["1-a.jpg"])
        try rig.files.write(second, to: "\(staging)/2-b.jpg", protection: .untilFirstUnlock)
        #expect(await rig.model.send())
        #expect(try rig.files.list(staging).isEmpty)
    }

    @Test func noContextAndOfflineCannotStartAThread() async throws {
        let rig = try await Rig(withContext: false)
        await rig.model.start([TextProvider()])
        #expect(rig.model.problem == .noContext)
        #expect(!rig.model.canSend)
    }

    @Test func cancelRemovesTheStaging() async throws {
        let rig = try await Rig()
        await rig.model.start([ImageProvider(name: "a.jpg")])
        let folder = try #require(try rig.files.list(InstanceFolders.shareStaging).first)
        #expect(try rig.files.list("\(InstanceFolders.shareStaging)/\(folder)") == ["1-a.jpg"])
        rig.model.cancel()
        #expect(try rig.files.list(InstanceFolders.shareStaging).isEmpty)
    }

    @Test func aFirstShareWithoutAContextUsesTheFetchedRepositories() async throws {
        let rig = try await Rig(withContext: false, options: Self.options())
        await rig.model.start([TextProvider()])
        #expect(rig.model.problem == nil)
        #expect(rig.model.form?.repo == "example/api")
        #expect(rig.model.canSend)
        #expect(!rig.model.isOffline)
    }

    private static func options() throws -> NewThreadOptions {
        let json = """
            {"repos":[{"slug":"example/api","name":"api","setupStatus":"ready","setupStep":null}],
             "picker":{"accounts":[],"defaultsByRepo":{},"models":[]},"defaultRepo":"example/api","models":[]}
            """
        return try JSONDecoder().decode(NewThreadOptions.self, from: Data(json.utf8))
    }
}
