import Foundation
import LocalAuthentication
import YakKit

#if canImport(os)
    import os
#endif

/// `.notInteractive` is an ask made while the app is not frontmost: nobody was asked, so it is neither a failure nor a
/// cancel, and the ask is made again when the app becomes active.
enum DeviceOwnerAuthenticationResult: Equatable { case success, cancelled, notInteractive, failed, passcodeNotSet }

/// What the device reported. `.unknown` is any failure to find out; only a confirmed missing passcode counts as one.
enum CapabilityReading: Equatable, Sendable {
    case known(LockCapability)
    case unknown
}

protocol DeviceOwnerAuthenticator: Sendable {
    /// What this device can do now, from a fresh context.
    func capability() async -> CapabilityReading
    func authenticate(reason: String) async -> DeviceOwnerAuthenticationResult
    /// Invalidates the context in use; its `authenticate` then answers `.cancelled`.
    func cancel()
}

/// The device's own passcode or biometry through `LAContext`.
final class LocalDeviceOwnerAuthenticator: DeviceOwnerAuthenticator, @unchecked Sendable {
    private let lock = NSLock()
    private var current: LAContext?

    func capability() async -> CapabilityReading {
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
        if LAContext().canEvaluatePolicy(.deviceOwnerAuthentication, error: &passcodeError) {
            return .known(LockCapability(biometry: biometry, hasPasscode: true))
        }
        // Only `passcodeNotSet` says there is no passcode; any other failure leaves the question open.
        if let passcodeError, passcodeError.domain == LAError.errorDomain,
            LAError.Code(rawValue: passcodeError.code) == .passcodeNotSet
        {
            return .known(LockCapability(biometry: biometry, hasPasscode: false))
        }
        return .unknown
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
            case .notInteractive: return .notInteractive
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
