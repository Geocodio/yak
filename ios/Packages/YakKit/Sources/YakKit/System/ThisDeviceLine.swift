import Foundation

/// What Settings shows for "This device". `me.device.name` is the model name sent at pairing and is never shown.
public struct ThisDeviceLine: Equatable, Sendable {
    /// ISO 8601 with or without fractional seconds, nil otherwise.
    public let pairedAt: Date?

    public static func make(device: Me.DeviceInfo?) -> ThisDeviceLine {
        guard let text = device?.pairedAt else {
            return ThisDeviceLine(pairedAt: nil)
        }
        let bounded = String(text.prefix(64))
        let withFraction = ISO8601DateFormatter()
        withFraction.formatOptions = [.withInternetDateTime, .withFractionalSeconds]
        let plain = ISO8601DateFormatter()
        plain.formatOptions = [.withInternetDateTime]
        return ThisDeviceLine(pairedAt: withFraction.date(from: bounded) ?? plain.date(from: bounded))
    }
}
