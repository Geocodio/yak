/// An identifier the server sends as a number or as a string. Stored as a string.
public struct LenientID: Codable, Sendable, Equatable, Hashable {
    public let value: String

    public init(_ value: String) { self.value = value }

    public init(from decoder: any Decoder) throws {
        let container = try decoder.singleValueContainer()
        if let text = try? container.decode(String.self) {
            value = text
        } else {
            value = String(try container.decode(Int.self))
        }
    }

    public func encode(to encoder: any Encoder) throws {
        var container = encoder.singleValueContainer()
        try container.encode(value)
    }
}
