import Foundation

/// A color identity per paired instance. Stored, so a new palette order never repaints an instance.
public struct InstanceTint: OpenValue {
    public let rawValue: String
    public init(rawValue: String) { self.rawValue = rawValue }

    public static let slate = InstanceTint(rawValue: "slate")
    public static let green = InstanceTint(rawValue: "green")
    public static let blue = InstanceTint(rawValue: "blue")
    public static let purple = InstanceTint(rawValue: "purple")
    public static let teal = InstanceTint(rawValue: "teal")
    public static let gold = InstanceTint(rawValue: "gold")

    /// In assignment order. The app has one color set per member; an unknown raw value is not in it.
    public static let palette: [InstanceTint] = [.slate, .green, .blue, .purple, .teal, .gold]

    public var isKnown: Bool { Self.palette.contains(self) }

    /// The first palette member not in `taken`; when all are taken, `palette[stableIndex(of: id)]`.
    public static func assign(for id: UUID, taken: [InstanceTint]) -> InstanceTint {
        palette.first { !taken.contains($0) } ?? palette[stableIndex(of: id)]
    }

    /// FNV-1a 64 over the UUID's 16 bytes, modulo the palette count. `hashValue` is seeded per process and never used.
    static func stableIndex(of id: UUID) -> Int {
        var hash: UInt64 = 14_695_981_039_346_656_037
        withUnsafeBytes(of: id.uuid) { bytes in
            for byte in bytes {
                hash = (hash ^ UInt64(byte)) &* 1_099_511_628_211
            }
        }
        return Int(hash % UInt64(palette.count))
    }
}
