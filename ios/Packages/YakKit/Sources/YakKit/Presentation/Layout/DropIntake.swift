import Foundation

/// One dropped item as the intake rule sees it.
public struct DroppedItem: Equatable, Sendable {
    /// From a stat of the item's file URL before any byte is read; nil when unknown.
    public let byteCount: Int?
    public let isImage: Bool

    public init(byteCount: Int?, isImage: Bool) {
        self.byteCount = byteCount
        self.isImage = isImage
    }
}

public enum DropDecision: Equatable, Sendable { case accept, tooMany, tooLarge, unreadable }

public struct DropSummary: Equatable, Sendable {
    public let accepted: Int
    public let tooMany: Int
    public let tooLarge: Int
    public let unreadable: Int

    public init(accepted: Int, tooMany: Int, tooLarge: Int, unreadable: Int) {
        self.accepted = accepted
        self.tooMany = tooMany
        self.tooLarge = tooLarge
        self.unreadable = unreadable
    }
}

public enum DropIntake {
    public static let maximumItemsLooked = 100
    /// Images are downscaled after reading, so they may be larger than the server's limit, up to this.
    public static let maximumImageReadBytes = 50_000_000
    public static let maximumNameCharacters = 60

    /// One decision per item, at most `maximumItemsLooked` (the rest count as tooMany in `summary`).
    public static func decide(
        _ items: [DroppedItem], attached: Int, maxFiles: Int, maxFileBytes: Int
    ) -> [DropDecision] {
        let places = (maxFiles < 0 || maxFileBytes < 0) ? 0 : maxFiles - max(0, attached)
        var accepted = 0
        return items.prefix(maximumItemsLooked).map { item in
            guard let bytes = item.byteCount, bytes >= 0 else { return .unreadable }
            let limit = item.isImage ? maximumImageReadBytes : maxFileBytes
            if maxFileBytes < 0 || bytes > limit { return .tooLarge }
            if accepted < places {
                accepted += 1
                return .accept
            }
            return .tooMany
        }
    }

    public static func summary(_ decisions: [DropDecision], dropped: Int) -> DropSummary {
        func count(_ decision: DropDecision) -> Int { decisions.filter { $0 == decision }.count }
        let unseen = max(0, dropped - decisions.count)
        return DropSummary(
            accepted: count(.accept), tooMany: count(.tooMany) + unseen, tooLarge: count(.tooLarge),
            unreadable: count(.unreadable))
    }

    /// The most bytes a bounded read of this item may return. The reader stops at this plus one byte,
    /// and a result over it is `.tooLarge` (a file that grew after the stat).
    public static func readLimit(isImage: Bool, maxFileBytes: Int) -> Int {
        isImage ? maximumImageReadBytes : max(0, maxFileBytes)
    }

    /// A name for a line: the safe file name, cut at 60 characters with an ellipsis.
    public static func lineName(_ fileName: String) -> String {
        let safe = ComposerAttachments.safeFileName(fileName)
        guard safe.count > maximumNameCharacters else { return safe }
        return String(safe.prefix(maximumNameCharacters - 1)) + "…"
    }
}
