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

@Test func anAttachmentNumberOutsideTheRangeDropsTheLastThread() throws {
    let files = InMemoryFileStore()
    for number in [0, -3, 10_001, Int.max] {
        var object = try base()
        var last = try #require(object["lastThread"] as? [String: Any])
        last["nextAttachmentNumber"] = number
        object["lastThread"] = last
        try store(object, files: files)
        let read = try #require(ShareContextStore.read(instance: instanceID, files: files))
        #expect(read.lastThread == nil)
    }
    var show = try decoded(ThreadShow.self, "scenario-thread-show")
    show = try changedShow(show) { object in
        var composer = object["composer"] as! [String: Any]
        composer["nextAttachmentNumber"] = Int.max
        object["composer"] = composer
    }
    #expect(ShareContextStore.lastThread(from: show).nextAttachmentNumber == 10_000)
}
