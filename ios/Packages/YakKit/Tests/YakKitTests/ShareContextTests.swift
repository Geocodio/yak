import Foundation
import Testing

@testable import YakKit

private let instanceID = UUID(uuidString: "11111111-1111-1111-1111-111111111111")!

private func store(_ object: [String: Any], files: InMemoryFileStore) throws {
    try files.write(
        JSONSerialization.data(withJSONObject: object), to: InstanceFolders.shareContext(instanceID),
        protection: .untilFirstUnlock)
}

private func base() throws -> [String: Any] {
    try #require(JSONSerialization.jsonObject(with: Scenarios.data("scenario-share-context")) as? [String: Any])
}

@Test func aContextRoundTripsThroughTheStore() throws {
    let files = InMemoryFileStore()
    let context = try decoded(ShareContext.self, "scenario-share-context")
    try ShareContextStore.write(context, instance: instanceID, files: files)
    #expect(ShareContextStore.read(instance: instanceID, files: files) == context)
    #expect(files.protection(of: InstanceFolders.shareContext(instanceID)) == .untilFirstUnlock)
}

@Test func aContextAboveTheBoundsIsRefusedOrCut() throws {
    let files = InMemoryFileStore()
    #expect(ShareContextStore.read(instance: instanceID, files: files) == nil)
    try files.write(Data(count: 300_000), to: InstanceFolders.shareContext(instanceID), protection: .untilFirstUnlock)
    #expect(ShareContextStore.read(instance: instanceID, files: files) == nil)
    try files.write(Data("garbage".utf8), to: InstanceFolders.shareContext(instanceID), protection: .untilFirstUnlock)
    #expect(ShareContextStore.read(instance: instanceID, files: files) == nil)
    try files.write(Data("{}".utf8), to: InstanceFolders.shareContext(instanceID), protection: .untilFirstUnlock)
    #expect(ShareContextStore.read(instance: instanceID, files: files) == nil)

    var object = try base()
    var options = try #require(object["options"] as? [String: Any])
    let repo = try #require((options["repos"] as? [[String: Any]])?.first)
    options["repos"] = (0..<500).map { index -> [String: Any] in
        var copy = repo
        copy["slug"] = "example/repo-\(index)"
        return copy
    }
    options["models"] = (0..<80).map { _ in (options["models"] as! [Any])[0] }
    object["options"] = options
    object["instanceName"] = String(repeating: "n", count: 1000)
    try store(object, files: files)
    let read = try #require(ShareContextStore.read(instance: instanceID, files: files))
    #expect(read.options?.repos.count == 200 && read.options?.models.count == 50 && read.instanceName.count == 200)

    for maxFiles in [0, 10_000] {
        var invalid = try base()
        invalid["limits"] = ["maxFiles": maxFiles, "maxFileBytes": 1000]
        try store(invalid, files: files)
        #expect(ShareContextStore.read(instance: instanceID, files: files) == nil)
    }
}

@Test func aTooLargeContextIsNotWritten() throws {
    let files = InMemoryFileStore()
    var context = try decoded(ShareContext.self, "scenario-share-context")
    context.lastRepo = String(repeating: "x", count: 300_000)
    #expect(throws: FileStoreError.tooLarge) {
        try ShareContextStore.write(context, instance: instanceID, files: files)
    }
}

@Test func limitsRoundTripThroughTheShareCopy() throws {
    let me = try decoded(Me.self, "scenario-me")
    let limits = ShareLimits(me.attachments)
    let back = try JSONDecoder().decode(ShareLimits.self, from: JSONEncoder().encode(limits))
    #expect(back == ShareLimits(maxFiles: 8, maxFileBytes: 10_485_760))
}

@Test func theSavedListMovesUnderTheShareName() async throws {
    let old = MemoryValues()
    let new = MemoryValues()
    old.set(Data("[]".utf8), forKey: "instances.v1")
    InstanceStore.moveSavedList(from: old, to: new)
    #expect(new.data(forKey: "instances.v1") != nil && old.data(forKey: "instances.v1") == nil)
}

private final class MemoryValues: KeyValueStore, @unchecked Sendable {
    private let lock = NSLock()
    private var values: [String: Data] = [:]
    func data(forKey key: String) -> Data? { lock.withLock { values[key] } }
    func set(_ value: Data?, forKey key: String) { lock.withLock { values[key] = value } }
}
