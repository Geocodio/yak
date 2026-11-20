import Foundation

/// The file a Quick Look download becomes on this device: a safe name, and a size cap.
public enum QuickLookFile {
    /// Larger files are not downloaded for a preview.
    public static let maximumBytes: Int64 = 100 * 1_024 * 1_024
    private static let maximumNameBytes = 100
    public static let fallbackName = "file"

    /// Whether a file of `bytes` may be kept.
    public static func isWithinLimit(bytes: Int64) -> Bool { bytes <= maximumBytes }

    /// Whether a download may start, from the length the response announces: an unknown (negative) or too large length
    /// is refused, so no file of unbounded size is written.
    public static func admits(expectedLength: Int64) -> Bool {
        expectedLength >= 0 && isWithinLimit(bytes: expectedLength)
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
