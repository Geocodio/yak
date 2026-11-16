import Foundation

/// A terminal session the server issued: a single-use token and the bridge URL that carries it.
public struct TerminalSession: Codable, Equatable, Sendable {
    /// Validated by `TerminalConnection` before it connects, not here.
    public let url: String
    public let token: String
    public let expiresAt: String

    public init(url: String, token: String, expiresAt: String) {
        self.url = url
        self.token = token
        self.expiresAt = expiresAt
    }

    enum CodingKeys: String, CodingKey {
        case url, token
        case expiresAt = "expires_at"
    }
}

/// Why a terminal request waits: `person_share`, `global_cap`, `memory_floor`. Unknown values are kept.
public struct TerminalWaitLimit: OpenValue {
    public let rawValue: String
    public init(rawValue: String) { self.rawValue = rawValue }
    public static let personShare = TerminalWaitLimit(rawValue: "person_share")
    public static let globalCap = TerminalWaitLimit(rawValue: "global_cap")
    public static let memoryFloor = TerminalWaitLimit(rawValue: "memory_floor")
}

/// The two successful answers of `POST threads/{id}/terminal-sessions`.
public enum TerminalSessionAnswer: Decodable, Equatable, Sendable {
    case ready(TerminalSession)
    /// A body with `"state": "waiting"`. `restoring` is false when the body has no such key.
    case waiting(limit: TerminalWaitLimit?, restoring: Bool)

    private enum CodingKeys: String, CodingKey {
        case state, limit, restoring
    }

    public init(from decoder: any Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        if try container.decodeIfPresent(String.self, forKey: .state) == "waiting" {
            self = .waiting(
                limit: try container.decodeIfPresent(TerminalWaitLimit.self, forKey: .limit),
                restoring: try container.decodeIfPresent(Bool.self, forKey: .restoring) ?? false)
        } else {
            self = .ready(try TerminalSession(from: decoder))
        }
    }
}

/// The value of the `Origin` header the terminal bridge checks.
public enum TerminalOrigin {
    /// "scheme://host[:port]" of the instance URL, lowercased, the default port left out; nil without a host.
    public static func make(instance: URL) -> String? {
        guard let scheme = instance.scheme?.lowercased(), scheme == "https" || scheme == "http",
            let host = instance.host?.lowercased(), !host.isEmpty
        else { return nil }
        let hostText = host.contains(":") ? "[\(host)]" : host
        let defaultPort = scheme == "https" ? 443 : 80
        guard let port = instance.port, port != defaultPort else { return "\(scheme)://\(hostText)" }
        return "\(scheme)://\(hostText):\(port)"
    }
}
