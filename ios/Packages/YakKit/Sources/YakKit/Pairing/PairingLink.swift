import Foundation

/// A `yak://pair?url=<https url>&code=<pairing code>` link, as scanned from the dashboard's QR code.
public struct PairingLink: Equatable, Sendable {
    public let instance: URL
    public let code: String

    public init?(url: URL) {
        guard url.scheme?.lowercased() == "yak", url.host?.lowercased() == "pair",
            let items = URLComponents(url: url, resolvingAgainstBaseURL: false)?.queryItems,
            let address = items.first(where: { $0.name == "url" })?.value,
            let code = items.first(where: { $0.name == "code" })?.value, !code.isEmpty,
            address.lowercased().hasPrefix("https://"), let instance = InstanceAddress.normalize(address)
        else { return nil }
        self.instance = instance
        self.code = code
    }
}
