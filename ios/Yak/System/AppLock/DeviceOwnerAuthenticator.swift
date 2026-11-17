import Foundation
import LocalAuthentication
import YakKit

#if canImport(os)
    import os
#endif

enum DeviceOwnerAuthenticationResult: Equatable { case success, cancelled, failed, passcodeNotSet }

protocol DeviceOwnerAuthenticator: Sendable {
    /// What this device can do now, from a fresh context.
    func capability() async -> LockCapability
    func authenticate(reason: String) async -> DeviceOwnerAuthenticationResult
    /// Invalidates the context in use; its `authenticate` then answers `.cancelled`.
    func cancel()
}

/// The device's own passcode or biometry through `LAContext`.
final class LocalDeviceOwnerAuthenticator: DeviceOwnerAuthenticator, @unchecked Sendable {
    private let lock = NSLock()
    private var current: LAContext?

    func capability() async -> LockCapability {
        let context = LAContext()
        var biometryError: NSError?
        _ = context.canEvaluatePolicy(.deviceOwnerAuthenticationWithBiometrics, error: &biometryError)
        let biometry: LockBiometry =
            switch context.biometryType {
            case .faceID: .faceID
            case .touchID: .touchID
            case .opticID: .opticID
            case .none: .none
            @unknown default: .other
            }
        var passcodeError: NSError?
        let hasPasscode = LAContext().canEvaluatePolicy(.deviceOwnerAuthentication, error: &passcodeError)
        return LockCapability(biometry: biometry, hasPasscode: hasPasscode)
    }

    func authenticate(reason: String) async -> DeviceOwnerAuthenticationResult {
        let context = LAContext()
        lock.withLock { current = context }
        defer { lock.withLock { current = nil } }
        do {
            _ = try await context.evaluatePolicy(.deviceOwnerAuthentication, localizedReason: reason)
            return .success
        } catch let error as LAError {
            switch error.code {
            case .userCancel, .systemCancel, .appCancel: return .cancelled
            case .passcodeNotSet: return .passcodeNotSet
            default:
                #if canImport(os)
                    Logger(subsystem: "Yak", category: "AppLock").error("Authentication failed: \(error.code.rawValue)")
                #endif
                return .failed
            }
        } catch {
            return .failed
        }
    }

    func cancel() { lock.withLock { current?.invalidate() } }
}
