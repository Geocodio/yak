import Foundation

/// One Pusher protocol frame. The server sends `data` as a JSON string; clients send it as an object.
public struct PusherFrame: Equatable, Sendable {
    public let event: String
    public let channel: String?
    /// The server sends data as a JSON string; an object is re-encoded to a string.
    public let data: String?

    public init(event: String, channel: String? = nil, data: String? = nil) {
        self.event = event
        self.channel = channel
        self.data = data
    }

    public static func decode(_ text: String) -> PusherFrame? {
        guard let bytes = text.data(using: .utf8),
            let object = try? JSONSerialization.jsonObject(with: bytes) as? [String: Any],
            let event = object["event"] as? String
        else { return nil }
        let channel = object["channel"] as? String
        let data: String?
        switch object["data"] {
        case nil, is NSNull: data = nil
        case let string as String: data = string
        case let other?:
            guard JSONSerialization.isValidJSONObject(other),
                let encoded = try? JSONSerialization.data(withJSONObject: other, options: [.sortedKeys])
            else { return nil }
            data = String(decoding: encoded, as: UTF8.self)
        }
        return PusherFrame(event: event, channel: channel, data: data)
    }

    /// Client frames send `data` as a JSON object (`{}` when there is none). A `data` string that is
    /// not a JSON object or array is sent as a string.
    public func encoded() -> String {
        var parts = ["\"event\":\(Self.quoted(event))"]
        if let channel { parts.append("\"channel\":\(Self.quoted(channel))") }
        parts.append("\"data\":\(dataFragment())")
        return "{" + parts.joined(separator: ",") + "}"
    }

    private func dataFragment() -> String {
        guard let data else { return "{}" }
        if let bytes = data.data(using: .utf8),
            let parsed = try? JSONSerialization.jsonObject(with: bytes),
            parsed is [String: Any] || parsed is [Any]
        {
            return data
        }
        return Self.quoted(data)
    }

    private static func quoted(_ string: String) -> String {
        let encoder = JSONEncoder()
        encoder.outputFormatting = [.withoutEscapingSlashes]
        guard let bytes = try? encoder.encode(string) else { return "\"\"" }
        return String(decoding: bytes, as: UTF8.self)
    }
}
