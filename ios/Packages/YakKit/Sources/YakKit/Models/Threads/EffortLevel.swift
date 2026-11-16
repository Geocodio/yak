/// How hard a model thinks. Declared in ascending order, so levels compare by case order.
public enum EffortLevel: String, Codable, CaseIterable, Comparable, Sendable {
    case low, medium, high, xhigh, max

    public static func < (lhs: EffortLevel, rhs: EffortLevel) -> Bool {
        allCases.firstIndex(of: lhs)! < allCases.firstIndex(of: rhs)!
    }

    public var displayName: String {
        switch self {
        case .low: "Low"
        case .medium: "Med"
        case .high: "High"
        case .xhigh: "XHigh"
        case .max: "Max"
        }
    }

    /// The supported level at or below `self`, or nil when there is none.
    public func clamped(to supported: [EffortLevel]) -> EffortLevel? {
        supported.filter { $0 <= self }.max()
    }
}

/// A missing `efforts` key means every level; unknown level names are dropped.
func decodeEfforts<K: CodingKey>(_ container: KeyedDecodingContainer<K>, _ key: K) throws -> [EffortLevel] {
    guard let names = try container.decodeIfPresent([String].self, forKey: key) else { return EffortLevel.allCases }
    return names.compactMap(EffortLevel.init(rawValue:))
}
