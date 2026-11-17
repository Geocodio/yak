import Foundation

/// A running time or a countdown as the chat shows it: minutes and seconds, with hours once there are any.
public enum ElapsedText {
    /// "0:07", "12:03" and "1:02:03". A negative or non-finite value reads as zero.
    public static func make(seconds: TimeInterval) -> String {
        let whole = seconds.isFinite ? max(0, Int(seconds.rounded(.down))) : 0
        let hours = whole / 3600
        let minutes = whole % 3600 / 60
        let rest = whole % 60
        if hours > 0 { return String(format: "%d:%02d:%02d", hours, minutes, rest) }
        return String(format: "%d:%02d", minutes, rest)
    }
}
