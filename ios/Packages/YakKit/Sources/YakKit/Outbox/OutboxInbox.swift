import Foundation

/// How another process, the share extension, hands an item to the app's outbox.
public enum OutboxInbox {
    static func folder(_ instance: UUID) -> String { "\(InstanceFolders.outbox(instance))/incoming" }
    static func itemPath(instance: UUID, id: UUID) -> String { "\(folder(instance))/\(id.uuidString).json" }
    static func itemsPath(_ instance: UUID) -> String { "\(InstanceFolders.outbox(instance))/items.json" }
    static func unreadableItemsPath(_ instance: UUID) -> String {
        "\(InstanceFolders.outbox(instance))/items.unreadable.json"
    }
    static func attachmentFolder(instance: UUID, item: UUID) -> String {
        "\(InstanceFolders.outbox(instance))/files/\(item.uuidString)"
    }

    /// Writes one item where the app's outbox picks it up. The caller writes each attachment's bytes to
    /// the `path` of its `OutboxAttachment`, which comes from `attachmentPath`, before calling this,
    /// with `.untilFirstUnlock` protection so the app can read them while the device is locked.
    public static func add(
        _ draft: OutboxDraft, instance: UUID, files: any FileStore, id: UUID, now: Date
    ) throws(FileStoreError) {
        let item = OutboxItem(
            id: id, target: draft.target, text: draft.text, model: draft.model, delivery: draft.delivery,
            attachments: draft.attachments, createdAt: now)
        let data: Data
        do { data = try JSONEncoder().encode(item) } catch { throw .unreadable }
        try files.write(data, to: itemPath(instance: instance, id: id), protection: .untilFirstUnlock)
    }

    /// Where an attachment's bytes live. The file name loses any path part, so it cannot leave the folder.
    public static func attachmentPath(instance: UUID, item: UUID, fileName: String) -> String {
        let safe = fileName.replacingOccurrences(of: "/", with: "_").replacingOccurrences(of: "\\", with: "_")
        let name = safe.isEmpty || safe == ".." || safe == "." ? "file" : safe
        return "\(attachmentFolder(instance: instance, item: item))/\(name)"
    }
}
