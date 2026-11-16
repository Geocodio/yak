public enum ComposerAttachments {
    /// "Image #N" or "File #N", numbered from `next` across both kinds, in order.
    public static func references(isImage: [Bool], next: Int) -> [String] {
        isImage.enumerated().map { index, isImage in "\(isImage ? "Image" : "File") #\(next + index)" }
    }

    /// Keeps A-Z a-z 0-9 . _ -, turns every other run into "-", cuts to 80 characters keeping the extension;
    /// "" becomes "file". Two or more dots in a row count as "other", so a name never climbs out of its folder.
    public static func safeFileName(_ name: String) -> String {
        let maximumLength = 80
        var result = ""
        var isInOtherRun = false
        let characters = Array(name)
        for (index, character) in characters.enumerated() {
            let isDot = character == "."
            let touchesDot =
                isDot
                && ((index > 0 && characters[index - 1] == ".")
                    || (index + 1 < characters.count && characters[index + 1] == "."))
            let isKept =
                !touchesDot && character.isASCII
                && (character.isLetter || character.isNumber || isDot || character == "_" || character == "-")
            if isKept {
                result.append(character)
                isInOtherRun = false
            } else if !isInOtherRun {
                result.append("-")
                isInOtherRun = true
            }
        }
        if result.isEmpty || result == "." { return "file" }
        guard result.count > maximumLength else { return result }
        if let dot = result.lastIndex(of: "."), result.distance(from: dot, to: result.endIndex) <= 12,
            dot != result.startIndex
        {
            let fileExtension = String(result[dot...])
            return String(result[..<dot].prefix(maximumLength - fileExtension.count)) + fileExtension
        }
        return String(result.prefix(maximumLength))
    }
}
