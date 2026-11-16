import Foundation

/// The extension's per-share folders for prepared images, which the app removes when they are old.
public enum ShareStaging {
    static let lifetime: TimeInterval = 3600

    /// `share-staging/<unix seconds>-<uuid>`.
    public static func folder(now: Date, id: UUID) -> String {
        "\(InstanceFolders.shareStaging)/\(Int(now.timeIntervalSince1970))-\(id.uuidString)"
    }

    /// Staging folders older than one hour, by the time in their name; names that do not parse, or lie more than an hour ahead, are expired.
    public static func expired(_ names: [String], now: Date) -> [String] {
        names.filter { name in
            guard
                let seconds = name.split(separator: "-", maxSplits: 1, omittingEmptySubsequences: false).first
                    .flatMap({ Int($0) })
            else { return true }
            return abs(now.timeIntervalSince1970 - Double(seconds)) > lifetime
        }
    }
}
