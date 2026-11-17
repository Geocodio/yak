import Foundation
import Testing
import YakKit

@testable import Yak

private final class RecordingSender: ShareSending, @unchecked Sendable {
    private let lock = NSLock()
    private(set) var sent: [(draft: OutboxDraft, staging: String, instance: UUID)] = []
    var failure: ShareSendProblem?

    func send(_ draft: OutboxDraft, stagingFolder: String, instance: UUID, token: String) async throws(ShareSendProblem)
    {
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

@MainActor
private struct Rig {
    let model: ShareModel
    let sender = RecordingSender()
    let files: DiskFileStore
    let instances: InstanceStore
    let tokens = InMemoryTokenStore()

    init(withInstance: Bool = true, withToken: Bool = true, withContext: Bool = true) async throws {
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
        model = ShareModel(
            instances: instances, tokens: tokens, files: files, sender: sender, clock: SystemClock(),
            fetchOptions: { _, _ in throw APIFailure.network(.notConnectedToInternet) })
    }
}

@MainActor
struct ShareModelTests {
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

    @Test func aFailedSendKeepsEverything() async throws {
        let rig = try await Rig()
        rig.sender.failure = .notSaved
        await rig.model.start([TextProvider()])
        rig.model.typed = "Keep me"
        #expect(await rig.model.send() == false)
        #expect(rig.model.phase == .failed)
        #expect(rig.model.typed == "Keep me")
        rig.model.dismissFailure()
        #expect(rig.model.phase == .ready)
    }

    @Test func noContextAndOfflineCannotStartAThread() async throws {
        let rig = try await Rig(withContext: false)
        await rig.model.start([TextProvider()])
        #expect(rig.model.problem == .noContext)
        #expect(!rig.model.canSend)
    }

    @Test func cancelRemovesTheStaging() async throws {
        let rig = try await Rig()
        await rig.model.start([])
        rig.model.cancel()
        #expect((try? rig.files.list(InstanceFolders.shareStaging))?.isEmpty ?? true)
    }
}
