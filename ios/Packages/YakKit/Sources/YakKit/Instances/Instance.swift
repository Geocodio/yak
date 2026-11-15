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

    public init(
        id: UUID = UUID(), url: URL, name: String, userName: String,
        needsYouCount: Int = 0, pushAvailable: Bool = false, lastUsedAt: Date
    ) {
        self.id = id
        self.url = url
        self.name = name
        self.userName = userName
        self.needsYouCount = needsYouCount
        self.pushAvailable = pushAvailable
        self.lastUsedAt = lastUsedAt
    }
}
