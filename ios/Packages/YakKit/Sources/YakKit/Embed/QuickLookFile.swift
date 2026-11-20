import Foundation

/// The file a Quick Look download becomes on this device: a safe name, and a size cap.
public enum QuickLookFile {
    /// Larger files are not downloaded for a preview.
    public static let maximumBytes: Int64 = 100 * 1_024 * 1_024
    private static let maximumNameBytes = 100
    public static let fallbackName = "file"

    /// Whether a file of `bytes` may be kept.
    public static func isWithinLimit(bytes: Int64) -> Bool { bytes <= maximumBytes }

    /// Whether a download may start, from the length the response announces. A response with no announced length
    /// (negative; a compressed, chunked answer has none) starts, because the running size is checked against the cap
    /// while it downloads and again when it ends. An announced length over the cap is refused.
    public static func admits(expectedLength: Int64) -> Bool {
        expectedLength < 0 || isWithinLimit(bytes: expectedLength)
    }

    /// What a downloaded file becomes.
    public enum Presentation: Equatable, Sendable {
        /// Quick Look shows it.
        case preview
        /// Quick Look is not used: the share sheet offers the file. Archives, pages and scripts that the system would
        /// run or render, and every type this list does not know.
        case share
    }

    /// The types Quick Look may show, by extension. Anything else, and above all anything a web view would run or
    /// render (`html`, `htm`, `xhtml`, `svg`, `js`), goes to the share sheet.
    private static let previewExtensions: Set<String> = [
        "pdf", "doc", "docx", "xls", "xlsx", "ppt", "pptx", "pages", "numbers", "key", "rtf",
        "txt", "text", "md", "log", "csv", "tsv", "json",
        "png", "jpg", "jpeg", "gif", "heic", "heif", "webp", "bmp", "tif", "tiff",
    ]

    public static func presentation(forFileName name: String) -> Presentation {
        let fileExtension = (fileName(name) as NSString).pathExtension.lowercased()
        return previewExtensions.contains(fileExtension) ? .preview : .share
    }

    /// Whether a running download has received more than the cap and must be cancelled.
    public static func mustCancel(receivedBytes: Int64) -> Bool { !isWithinLimit(bytes: receivedBytes) }

    /// `name` as one path component: separators and control characters become "_", leading dots go (no hidden file,
    /// no "..") and the name is cut to 100 bytes with its extension kept. Empty gives `fallbackName`.
    public static func fileName(_ name: String) -> String {
        var cleaned = ""
        for scalar in name.unicodeScalars {
            let isControl = scalar.properties.generalCategory == .control
            cleaned.unicodeScalars.append(isControl || scalar == "/" || scalar == "\\" || scalar == ":" ? "_" : scalar)
        }
        cleaned = String(cleaned.drop { $0 == "." || $0 == " " }).trimmingCharacters(in: .whitespaces)
        guard !cleaned.isEmpty else { return fallbackName }
        guard cleaned.utf8.count > maximumNameBytes else { return cleaned }
        let fileExtension = (cleaned as NSString).pathExtension
        let suffix = fileExtension.isEmpty || fileExtension.utf8.count > 16 ? "" : "." + fileExtension
        var stem = String(cleaned.dropLast(suffix.count))
        while (stem + suffix).utf8.count > maximumNameBytes, !stem.isEmpty { stem.removeLast() }
        return stem.isEmpty ? fallbackName : stem + suffix
    }
}
