import Foundation
import Testing
import YakKit

// Plain `import YakKit`, never `@testable`: the app builds these values with public initializers only.

@Test func embedModelsAreBuildableOutsideTheModule() async {
    #expect(WebSession(redeemUrl: "https://yak.example.com/app/embed/session", code: "c", expiresAt: "t").code == "c")
    let item = EmbedOutboxItem(
        key: "k", text: "t", status: .queued, reason: nil, createdAt: Date(timeIntervalSince1970: 0),
        attachmentCount: 0)
    #expect(item.key == "k")
    let limits = OfflineCopyLimits(maximumArchiveBytes: 10, maximumThreads: 2, maximumTotalBytes: 20)
    #expect(limits.maximumThreads == 2)
    let store = OfflineCopyStore(instance: UUID(), files: InMemoryFileStore(), limits: limits)
    #expect(await store.entries().isEmpty)
    #expect(OfflineCopyEntry(threadID: "t", bytes: 1, savedAt: Date(timeIntervalSince1970: 0), useOrder: 0).bytes == 1)
    #expect(OfflineCopy(archive: Data(), savedAt: Date(timeIntervalSince1970: 0)).archive.isEmpty)
    let timing = WebConversationMachine.Timing(
        slowAfter: .seconds(1), failAfter: .seconds(2), reMintInterval: .seconds(3))
    var machine = WebConversationMachine(
        supportsEmbeddedThread: true, hasOfflineCopy: false, threadID: "d0e515ef", timing: timing)
    #expect(machine.handle(.begin, at: ContinuousClock.now) == [.mint])
    let prewarm = WebPrewarmPolicy(
        isActive: true, isLocked: false, isSessionActive: true, isOnline: true, isLowDataMode: false,
        serverOffersPage: true, hasPreparedPage: false, candidateThreadID: "d0e515ef")
    #expect(prewarm.threadToPrepare == "d0e515ef")
}
