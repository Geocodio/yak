import Testing
import UIKit
import YakKit

@testable import Yak

@MainActor @Suite struct AppLockWindowsTests {
    private func controller() -> AppLockController {
        struct Authenticator: DeviceOwnerAuthenticator {
            func capability() async -> CapabilityReading { .unknown }
            func authenticate(reason: String) async -> DeviceOwnerAuthenticationResult { .cancelled }
            func cancel() {}
        }
        struct Store: KeyValueStore {
            func data(forKey key: String) -> Data? { nil }
            func set(_ value: Data?, forKey key: String) {}
        }
        return AppLockController(
            settings: PrivacySettings(store: Store()), authenticator: Authenticator(), clock: ManualClock())
    }

    private func window(level: UIWindow.Level = .normal) throws -> UIWindow {
        let scene = try #require(UIApplication.shared.connectedScenes.first as? UIWindowScene)
        let window = UIWindow(windowScene: scene)
        window.windowLevel = level
        return window
    }

    @Test func theProcessMakesOneSetOfLockWindows() {
        let controller = controller()
        var made = 0
        for _ in 0..<3 {
            controller.startWindowsOnce {
                made += 1
                return NSObject()
            }
        }
        #expect(made == 1)
    }

    @Test func noLockWindowIsEverHiddenFromAccessibility() throws {
        let app = try window()
        let keyboardLike = try window(level: .alert + 1)
        let lock = try window(level: .alert + 1)
        let hidden = AppLockInput.windowsToHide(in: [app, keyboardLike, lock], lock: lock)
        #expect(hidden == [app])
    }
}
