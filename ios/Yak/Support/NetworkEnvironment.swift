import SwiftUI

private struct IsNetworkOnlineKey: EnvironmentKey { static let defaultValue = true }

extension EnvironmentValues {
    /// Whether the device has a network path right now, set once at the root so any screen can say "offline".
    var isNetworkOnline: Bool {
        get { self[IsNetworkOnlineKey.self] }
        set { self[IsNetworkOnlineKey.self] = newValue }
    }
}
