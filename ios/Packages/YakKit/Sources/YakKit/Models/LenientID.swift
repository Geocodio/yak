/// An identifier the server sends as a number or as a string. Stored as a string.
public struct LenientID: Codable, Sendable, Equatable, Hashable {
    public let value: String

    public init(_ value: String) { self.value = value }

    public init(from decoder: any Decoder) throws {
        let container = try decoder.singleValueContainer()
        // Decode probe: an id is a string or a number, so a string that does not decode falls through to the number.
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
