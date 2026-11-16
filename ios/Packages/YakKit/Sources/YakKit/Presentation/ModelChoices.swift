public struct ModelChoiceRow: Equatable, Sendable, Identifiable {
    public enum Kind: Equatable, Sendable {
        case family(hasOlder: Bool, isExpanded: Bool)
        case pinned
    }
    public let id: String
    public let label: String
    public let detail: String?
    public let kind: Kind
    public let isSelected: Bool

    public init(id: String, label: String, detail: String?, kind: Kind, isSelected: Bool) {
        self.id = id
        self.label = label
        self.detail = detail
        self.kind = kind
        self.isSelected = isSelected
    }
}

/// The model picker's two levels: each family's latest version, with older versions under it.
public enum ModelChoices {
    public static func rows(families: [ModelFamily], selected: String?, expanded: Set<String>) -> [ModelChoiceRow] {
        families.flatMap { family -> [ModelChoiceRow] in
            let isExpanded = expanded.contains(family.family)
            var result = [
                ModelChoiceRow(
                    id: family.latest.value, label: family.latest.label, detail: family.latest.description,
                    kind: .family(hasOlder: !family.older.isEmpty, isExpanded: isExpanded),
                    isSelected: selected == family.latest.value)
            ]
            if isExpanded {
                result += family.older.map {
                    ModelChoiceRow(
                        id: $0.value, label: $0.label, detail: nil, kind: .pinned, isSelected: selected == $0.value)
                }
            }
            return result
        }
    }

    public static func label(for value: String?, in families: [ModelFamily]) -> String? {
        guard let value else { return nil }
        for family in families {
            if value == family.latest.value || value == family.family { return family.latest.label }
            if let version = family.older.first(where: { $0.value == value }) { return version.label }
        }
        return value
    }

    public static func isPinned(_ value: String, in families: [ModelFamily]) -> Bool {
        families.contains { $0.older.contains { $0.value == value } }
    }
}
