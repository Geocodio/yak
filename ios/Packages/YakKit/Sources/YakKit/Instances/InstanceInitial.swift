public enum InstanceInitial {
    private static let lookedAt = 64
    private static let longestGrapheme = 8

    /// The first letter or digit among the first 64 characters of `name` (a character of more than 8 scalars is
    /// skipped), uppercased to one character; else the first letter of `host`; else "Y".
    public static func make(name: String, host: String?) -> String {
        first(in: name) ?? host.flatMap(first(in:)) ?? "Y"
    }

    private static func first(in text: String) -> String? {
        for character in text.prefix(lookedAt)
        where character.unicodeScalars.count <= longestGrapheme && (character.isLetter || character.isNumber) {
            return character.uppercased().first.map(String.init)
        }
        return nil
    }
}
