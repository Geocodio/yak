import Foundation

/// One Yak instance this phone is paired with. The token is not stored here; see `TokenStore`.
public struct Instance: Codable, Identifiable, Equatable, Sendable {
    public let id: UUID
    /// Normalized, no trailing slash.
    public var url: URL
    public var name: String
    public var userName: String
    public var needsYouCount: Int
    public var pushAvailable: Bool
    public var lastUsedAt: Date
    /// Whether this instance can push to this build; nil until `me` was read (see `pushAvailable`).
    public var push: PushAvailability?
    /// The address the server reports for itself, normalized; nil until `me` answers. Pushes name the instance by it.
    public var canonicalURL: URL?

    public init(
        id: UUID = UUID(), url: URL, name: String, userName: String,
        needsYouCount: Int = 0, pushAvailable: Bool = false, lastUsedAt: Date,
        push: PushAvailability? = nil, canonicalURL: URL? = nil
    ) {
        self.id = id
        self.url = url
        self.name = name
        self.userName = userName
        self.needsYouCount = needsYouCount
        self.pushAvailable = pushAvailable
        self.lastUsedAt = lastUsedAt
        self.push = push
        self.canonicalURL = canonicalURL
    }
}
