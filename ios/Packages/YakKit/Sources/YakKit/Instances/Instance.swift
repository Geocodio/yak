import Foundation

#if canImport(os)
    import os
#endif

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
    /// Given when the instance is paired and never changed; nil only in memory before the store assigns one.
    public var tint: InstanceTint?

    public init(
        id: UUID = UUID(), url: URL, name: String, userName: String,
        needsYouCount: Int = 0, pushAvailable: Bool = false, lastUsedAt: Date,
        push: PushAvailability? = nil, canonicalURL: URL? = nil,
        tint: InstanceTint? = nil
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
        self.tint = tint
    }

    private enum CodingKeys: String, CodingKey {
        case id, url, name, userName, needsYouCount, pushAvailable, lastUsedAt, push, canonicalURL, tint
    }

    public init(from decoder: any Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        id = try container.decode(UUID.self, forKey: .id)
        url = try container.decode(URL.self, forKey: .url)
        name = try container.decode(String.self, forKey: .name)
        userName = try container.decode(String.self, forKey: .userName)
        needsYouCount = try container.decode(Int.self, forKey: .needsYouCount)
        pushAvailable = try container.decode(Bool.self, forKey: .pushAvailable)
        lastUsedAt = try container.decode(Date.self, forKey: .lastUsedAt)
        push = try container.decodeIfPresent(PushAvailability.self, forKey: .push)
        canonicalURL = try container.decodeIfPresent(URL.self, forKey: .canonicalURL)
        // A tint of the wrong type must not make the whole list unreadable; the store assigns a new one.
        do {
            tint = try container.decodeIfPresent(String.self, forKey: .tint).map(InstanceTint.init(rawValue:))
        } catch {
            #if canImport(os)
                let kind = String(describing: type(of: error))
                Logger(subsystem: "YakKit", category: "Instance")
                    .error("Stored instance tint is unreadable: \(kind, privacy: .public)")
            #endif
            tint = nil
        }
    }
}
