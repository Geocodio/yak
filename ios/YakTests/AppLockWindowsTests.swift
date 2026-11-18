import Testing
import UIKit
import YakKit

@testable import Yak

@MainActor @Suite struct AppLockWindowsTests {
    private struct Authenticator: DeviceOwnerAuthenticator {
        func capability() async -> CapabilityReading { .unknown }
        func authenticate(reason: String) async -> DeviceOwnerAuthenticationResult { .success }
        func cancel() {}
    }

    private final class Store: KeyValueStore, @unchecked Sendable {
        private let lock = NSLock()
        private var values: [String: Data] = [:]
        func data(forKey key: String) -> Data? { lock.withLock { values[key] } }
        func set(_ value: Data?, forKey key: String) { lock.withLock { values[key] = value } }
    }

    private func controller(lockOn: Bool = false) -> AppLockController {
        let settings = PrivacySettings(store: Store())
        settings.appLockOn = lockOn
        return AppLockController(settings: settings, authenticator: Authenticator(), clock: ManualClock())
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

    @Test func theLockWindowTakesKeyAndEndsEditingThenGivesKeyBack() throws {
        let app = try window()
        let field = UITextField()
        app.addSubview(field)
        app.makeKeyAndVisible()
        #expect(field.becomeFirstResponder())
        let lock = try window(level: .alert + 1)
        lock.isHidden = false
        let handoff = LockKeyHandoff()
        handoff.engage(lock: lock, covering: [app])
        #expect(lock.isKeyWindow)
        #expect(!field.isFirstResponder)
        handoff.release()
        #expect(app.isKeyWindow)
        lock.isHidden = true
    }

    @Test func shortcutsDoNothingWhileBlocked() {
        #expect(AppShortcutGate.keyboardShortcut(for: .send, blocked: true) == nil)
        #expect(AppShortcutGate.keyboardShortcut(for: .send, blocked: false) != nil)
    }

    @Test func aCoveredWindowThatBecomesKeyWhileLockedLosesKeyAndEditing() throws {
        let app = try window()
        let field = UITextField()
        app.addSubview(field)
        app.makeKeyAndVisible()
        let lock = try window(level: .alert + 1)
        lock.isHidden = false
        let center = NotificationCenter()
        let handoff = LockKeyHandoff(center: center)
        handoff.engage(lock: lock, covering: [app])
        #expect(lock.isKeyWindow)
        // Something focuses the composer: the covered window takes key and a field becomes first responder.
        app.makeKey()
        _ = field.becomeFirstResponder()
        center.post(name: UIWindow.didBecomeKeyNotification, object: app)
        #expect(lock.isKeyWindow)
        #expect(!field.isFirstResponder)
        handoff.release()
        app.makeKey()
        center.post(name: UIWindow.didBecomeKeyNotification, object: app)
        #expect(app.isKeyWindow)
        lock.isHidden = true
    }

    @Test func aLinkThatArrivesWhileLockedOpensOnlyAfterTheUnlockAndTheNewestWins() async {
        let controller = controller(lockOn: true)
        var opened: [String] = []
        controller.whenOpen { opened.append("first") }
        controller.whenOpen { opened.append("second") }
        #expect(opened.isEmpty)
        await controller.requestUnlock()
        for _ in 0..<100 where opened.isEmpty { await Task.yield() }
        #expect(opened == ["second"])
        controller.whenOpen { opened.append("later") }
        for _ in 0..<100 where opened.count < 2 { await Task.yield() }
        #expect(opened == ["second", "later"])
    }
}
