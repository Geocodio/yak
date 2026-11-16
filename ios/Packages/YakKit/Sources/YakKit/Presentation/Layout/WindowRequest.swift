import Foundation

/// What a window of the app is asked to show. It holds ids only, never a title.
public enum WindowRequest: Codable, Hashable, Sendable {
    /// A new window with no thread; the nonce makes each one new.
    case blank(UUID)
    /// `id` is exactly 8 ASCII letters or digits.
    case thread(instance: UUID, id: String)

    private enum CodingKeys: String, CodingKey {
        case kind, nonce, instance, id
    }

    /// SwiftUI restores and passes a `WindowGroup(for:)` value through this initializer, so this is where the value
    /// is checked: the kind is "blank" or "thread", the instance is a UUID and the id is exactly 8 ASCII letters or
    /// digits. Anything else throws `DecodingError.dataCorrupted`.
    public init(from decoder: any Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        let kind = try container.decode(String.self, forKey: .kind)
        switch kind {
        case "blank":
            self = .blank(try container.decode(UUID.self, forKey: .nonce))
        case "thread":
            let instance = try container.decode(UUID.self, forKey: .instance)
            let id = try container.decode(String.self, forKey: .id)
            guard Self.isValidThreadID(id) else {
                throw DecodingError.dataCorruptedError(
                    forKey: .id, in: container, debugDescription: "A thread id is 8 ASCII letters or digits.")
            }
            self = .thread(instance: instance, id: id)
        default:
            throw DecodingError.dataCorruptedError(
                forKey: .kind, in: container, debugDescription: "Unknown window kind.")
        }
    }

    public func encode(to encoder: any Encoder) throws {
        var container = encoder.container(keyedBy: CodingKeys.self)
        switch self {
        case .blank(let nonce):
            try container.encode("blank", forKey: .kind)
            try container.encode(nonce, forKey: .nonce)
        case .thread(let instance, let id):
            try container.encode("thread", forKey: .kind)
            try container.encode(instance, forKey: .instance)
            try container.encode(id, forKey: .id)
        }
    }

    /// The thread this window opens, or nil for a blank window and for a request meant for another instance.
    public func threadToOpen(activeInstance: UUID) -> String? {
        guard case .thread(let instance, let id) = self, instance == activeInstance else { return nil }
        return id
    }

    /// A window that the system restores keeps a thread only when it was stored for the active instance (nil: stored
    /// before windows existed, kept).
    public static func keepsRestoredThread(storedInstance: UUID?, activeInstance: UUID) -> Bool {
        storedInstance == nil || storedInstance == activeInstance
    }

    private static func isValidThreadID(_ id: String) -> Bool {
        id.utf8.count == 8 && id.utf8.allSatisfy { ($0 >= 48 && $0 <= 57) || ($0 >= 65 && $0 <= 90) || ($0 >= 97 && $0 <= 122) }
    }
}

/// The scene title of a window.
public enum WindowTitle: Equatable, Sendable {
    case none
    case thread(String)
    case privateThread

    public static let maximumLength = 120

    public static func make(_ thread: ThreadDetail?) -> WindowTitle {
        guard let thread else { return .none }
        if thread.isPrivate { return .privateThread }
        let trimmed = thread.title.trimmingCharacters(in: .whitespacesAndNewlines)
        if trimmed.isEmpty { return .none }
        return .thread(String(trimmed.prefix(maximumLength)))
    }
}
